<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Local (platform-less) accounts — anonymity first:
 * username + password, NO email required. Recovery relies on one-time
 * codes generated at registration; without them a lost password means a
 * lost account, and the UI says so explicitly.
 */
class LocalAuthController extends Controller
{
    use ValidatesRedirects;

    private const USERNAME_RULE = 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,23}$/';

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:24', self::USERNAME_RULE],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
        ], [
            'username.regex' => __('auth.username_format'),
        ]);

        $username = strtolower($validated['username']);

        // ⚠ **Both columns, not just the unique one.** `username` carries the unique index, but the
        // site displays `name` — and accounts created through a provider have a name and no
        // username at all. Checking only the index let a new local account take the displayed name
        // of an existing provider account, which is the impersonation this is here to stop.
        if (User::where('username', $username)->exists()
            || User::displayNameTaken($validated['username'])) {
            throw ValidationException::withMessages(['username' => __('auth.username_taken')]);
        }

        $user = new User();
        $user->forceFill([
            'name' => $validated['username'],
            'username' => $username,
            'email' => null,
            'password' => Hash::make($validated['password']),
            'provider' => 'local',
            'locale' => app()->getLocale(),
            // They just chose their name — no need for the one-shot rename prompt
            'username_prompt_seen_at' => now(),
        ])->save();

        $codes = RecoveryCode::generateFor($user);

        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::logLogin($user->id, 'local', $request);

        // One-time display: the codes are flashed, never stored in plain
        return redirect()->route('local.recovery-codes')->with('recovery_codes', $codes);
    }

    /**
     * One-time recovery codes screen, right after registration or regeneration.
     */
    public function showRecoveryCodes(Request $request)
    {
        $codes = $request->session()->get('recovery_codes');
        if (!$codes) {
            return redirect()->route('home');
        }

        return view('auth.recovery-codes', ['codes' => $codes]);
    }

    /**
     * Regenerate recovery codes from the profile (password required).
     */
    public function regenerateCodes(Request $request)
    {
        $user = $request->user();
        if (!$user->isLocalAccount()) {
            abort(403);
        }

        $request->validate(['password' => ['required', 'string']]);
        if (!Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => __('auth.wrong_password')]);
        }

        $codes = RecoveryCode::generateFor($user);

        return redirect()->route('local.recovery-codes')->with('recovery_codes', $codes);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:24'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $username = strtolower($validated['username']);
        $throttleKey = 'local-login:' . $username . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $user = User::where('username', $username)->where('provider', 'local')->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            // Same message whether the username exists or not (no enumeration)
            throw ValidationException::withMessages(['username' => __('auth.login_failed')]);
        }

        if ($user->isBanned()) {
            throw ValidationException::withMessages(['username' => __('auth.account_banned')]);
        }

        RateLimiter::clear($throttleKey);

        // ⚠ Asked, not assumed. This used to be `remember: true` outright, so every sign-in left a
        // long-lived cookie on whatever machine it happened on — including one somebody had
        // borrowed. Unticked, the session ends when the browser closes.
        Auth::login($user, remember: $request->boolean('remember'));

        // 🔴 **Read AFTER regenerate(), written back by hand.** Regenerating the session is what
        // stops a fixation attack, and it wipes url.intended along with everything else — so the
        // address has to be carried across it. Signing in from /link and landing on the home page
        // means finding /link again with a code still on screen in the game.
        //
        // ⚠ Same validation as the providers use (ValidatesRedirects): where somebody is sent
        // after signing in must never be an address of their choosing.
        $intended = $this->validateRedirectUrl($request->input('redirect'))
            ?? session('url.intended');

        $request->session()->regenerate();
        AuditLog::logLogin($user->id, 'local', $request);

        if ($intended) {
            return redirect()->to($intended);
        }

        return redirect()->intended(route('home'));
    }

    public function showRecover()
    {
        return view('auth.recover');
    }

    /**
     * Account recovery with a one-time code: sets a new password and
     * burns the code.
     */
    public function recover(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:24'],
            'recovery_code' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
        ]);

        $username = strtolower($validated['username']);
        $throttleKey = 'local-recover:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'recovery_code' => __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $user = User::where('username', $username)->where('provider', 'local')->first();
        $code = $user ? RecoveryCode::find($user, $validated['recovery_code']) : null;

        if (!$code) {
            RateLimiter::hit($throttleKey, 3600);
            throw ValidationException::withMessages(['recovery_code' => __('auth.recovery_failed')]);
        }

        // 🔴 **The same door as login(), and it was missing here.** A banned account could not sign
        // in with its password but could type a recovery code, set a new password and get a
        // session — for one request, until CheckBanned caught it. Tested AFTER the code, as the
        // ban is tested after the password above: only somebody holding a valid credential learns
        // the account is banned, and their code stays unburnt for the day it is allowed back.
        if ($user->isBanned()) {
            throw ValidationException::withMessages(['username' => __('auth.account_banned')]);
        }

        RateLimiter::clear($throttleKey);
        $code->burn();
        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::logLogin($user->id, 'local-recovery', $request);

        return redirect()->route('home')->with('success', __('auth.recovered'));
    }
}
