<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialController extends Controller
{
    use ValidatesRedirects;

    protected array $providers = ['google', 'github', 'discord', 'twitch', 'steam', 'epicgames'];

    public function redirect(Request $request, string $provider)
    {
        if (!in_array($provider, $this->providers)) {
            abort(404);
        }

        // Where to land afterwards, refused unless it is one of our own addresses.
        $this->rememberIntendedUrl($request->query('redirect'));

        return Socialite::driver($provider)->redirect();
    }

    // validateRedirectUrl() and rememberIntendedUrl() now live in ValidatesRedirects, shared with
    // the local sign-in — which had no such rule at all and ignored where somebody came from.

    public function callback(string $provider)
    {
        if (!in_array($provider, $this->providers)) {
            abort(404);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            \Log::error("OAuth {$provider} error: " . $e->getMessage());

            // Show details in debug mode, generic message in production
            $errorMessage = config('app.debug')
                ? "Authentication failed ({$provider}): " . $e->getMessage()
                : "Authentication failed. Please try again or use another provider.";

            return redirect()->route('login')->with('error', $errorMessage);
        }

        // Get email (some providers like Steam don't provide email)
        $email = $socialUser->getEmail();

        // For providers without email, generate a placeholder
        if (empty($email)) {
            // Sanitize ID to prevent email injection (only alphanumeric)
            $sanitizedId = preg_replace('/[^a-zA-Z0-9]/', '', $socialUser->getId());
            $email = $sanitizedId . '@' . $provider . '.local';
        }

        // Check for disposable email domains (skip for generated emails)
        if (!str_ends_with($email, '.local') && $this->isDisposableEmail($email)) {
            return redirect()->route('login')->with('error', 'Disposable emails are not allowed.');
        }

        // Find or create user
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (!$user) {
            // Check if email exists with different provider (skip for generated emails)
            $existingUser = !str_ends_with($email, '.local')
                ? User::where('email', $email)->first()
                : null;

            if ($existingUser) {
                // Security: do NOT silently overwrite provider — this would allow
                // account takeover by creating a new OAuth account with the victim's email.
                // Instead, reject the login and inform the user.
                return redirect()->route('login')->with('error',
                    'An account with this email already exists (via ' . ucfirst($existingUser->provider) . '). '
                    . 'Please log in with your original provider to access your account.'
                );
            } else {
                // 🔴 **The provider's avatar is not taken at all — see the note below.**
                $user = User::create([
                    'name' => $this->availableDisplayName(
                        $socialUser->getName() ?? $socialUser->getNickname() ?? 'User'),
                    'email' => $email,
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'email_verified_at' => now(),
                ]);

                // 🔴 **Nothing here may link this account to the one it signed in with.**
                //
                // The provider's avatar URL carries the provider's own user id —
                // `avatars.githubusercontent.com/u/12345` — and GitHub resolves that id to a login
                // through a public, unauthenticated endpoint. Rendered on a game page, it tied a
                // site pseudonym to a real GitHub account for any visitor reading the HTML, on a
                // site whose sign-up is advertised as anonymous. It also had every visitor's
                // browser fetch an image from GitHub or Discord, handing those hosts the IP of
                // everyone reading a public page.
                //
                // ⚠ **Not stored rather than stored-and-not-shown.** Keeping the URL for an opt-in
                // would have left the identifier in our database, and an identifier we hold is one
                // we can leak later — through an export, an admin screen, a future template. The
                // choice it would have served is thin: a generated avatar is what somebody signing
                // in anonymously came for. So the field stays empty and there is nothing to publish.
                //
                // ⚠ A seed all the same, so "reroll" has something to move. Without it the
                // component still generates one from the user id, so this is comfort, not cover.
                //
                // ⚠ forceFill, because avatar_seed is deliberately outside $fillable — mass
                // assignment must not reach it. Passing it to create() would have been dropped in
                // silence, which is the whole trap.
                $user->forceFill(['avatar_seed' => Str::random(20)])->save();
            }
        } elseif (!empty($user->avatar)) {
            // 🔴 **Only follows an avatar this account ALREADY holds.** Accounts created before
            // 2026-08-24 kept the provider URL, and somebody still using it should see it change
            // when they change it upstream. Without the guard this line would put the URL back on
            // every account at the next sign-in, undoing the whole point above — the kind of
            // repair that reads as harmless and quietly reopens what was closed.
            $user->update(['avatar' => $socialUser->getAvatar()]);
        }

        // Check if user is banned
        if ($user->isBanned()) {
            return redirect()->route('login')->with('error', 'Your account has been banned. Reason: ' . ($user->ban_reason ?? 'No reason provided.'));
        }

        Auth::login($user, true);

        // Log successful login
        AuditLog::logLogin($user->id, $provider);

        return redirect()->intended('/');
    }

    /**
     * A display name nobody else holds, built from whatever the provider handed us.
     *
     * 🔴 **This is the front door, and it was the one with no lock.** Renaming has enforced
     * `^[a-zA-Z0-9_\-]+$` and a 30-day delay for months — against homoglyphs, against impersonation
     * — while creation through a provider took `getName()` exactly as given: any script, any
     * spacing, and any name already borne by somebody here.
     *
     * ⚠ **Corrected rather than refused.** Nobody chose this name — the provider supplied it — so
     * turning somebody away at the door over it would be punishing them for their Google account.
     * They land with a usable name and can change it from their profile, which is what the one-shot
     * prompt already invites them to do.
     *
     * ⚠ Non-Latin names lose everything to the filter, hence the fallback: an account is better
     * named "player4173" than "". The prompt then asks them to pick something, in a field that
     * accepts what this one cannot.
     */
    protected function availableDisplayName(string $wanted): string
    {
        // Same charset as the rename, so both doors lead to the same set of names.
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $wanted) ?? '';
        $clean = mb_substr($clean, 0, 50);

        if (mb_strlen($clean) < 2) $clean = 'player' . random_int(1000, 9999);

        if (!User::displayNameTaken($clean)) return $clean;

        $free = User::suggestDisplayNames($clean, 1);

        // Everything up to <name>200 taken: a random tail rather than a refusal, since this runs
        // during a sign-in nobody can retry differently.
        return $free[0] ?? mb_substr($clean, 0, 45) . random_int(1000, 9999);
    }

    protected function isDisposableEmail(string $email): bool
    {
        // Note: OAuth providers already verify emails in most cases.
        // This is a defense-in-depth measure, not a primary protection.
        $disposableDomains = [
            'tempmail.com', 'throwaway.email', 'guerrillamail.com',
            'mailinator.com', 'tempmail.net', 'fakeinbox.com',
            '10minutemail.com', 'trashmail.com', 'yopmail.com',
            'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
            'dispostable.com', 'mailnesia.com', 'maildrop.cc',
            'discard.email', 'temp-mail.org', 'mohmal.com',
            'getnada.com', 'emailondeck.com', 'tempail.com',
            'crazymailing.com', 'mytemp.email', 'throwme.away',
            'tempinbox.com', 'filzmail.com', 'inboxbear.com',
            'jetable.org', 'mintemail.com', 'spamgourmet.com',
            'harakirimail.com', 'mailcatch.com', 'meltmail.com',
            'throwam.com', 'tempr.email', 'burnermail.io',
            'mailsac.com', 'moakt.co', 'tempmailo.com',
        ];

        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, $disposableDomains, true);
    }
}
