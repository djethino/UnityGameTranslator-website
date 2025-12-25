<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialController extends Controller
{
    protected array $providers = ['google', 'github', 'discord', 'twitch'];

    public function redirect(string $provider)
    {
        if (!in_array($provider, $this->providers)) {
            abort(404);
        }

        return Socialite::driver($provider)->redirect();
    }

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

        // Check for disposable email domains
        if ($this->isDisposableEmail($socialUser->getEmail())) {
            return redirect()->route('login')->with('error', 'Disposable emails are not allowed.');
        }

        // Find or create user
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (!$user) {
            // Check if email exists with different provider
            $existingUser = User::where('email', $socialUser->getEmail())->first();
            
            if ($existingUser) {
                // Link this provider to existing account
                $existingUser->update([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'avatar' => $socialUser->getAvatar(),
                ]);
                $user = $existingUser;
            } else {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'email' => $socialUser->getEmail(),
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => now(),
                ]);
            }
        } else {
            // Update avatar if changed
            $user->update(['avatar' => $socialUser->getAvatar()]);
        }

        // Check if user is banned
        if ($user->isBanned()) {
            return redirect()->route('login')->with('error', 'Your account has been banned. Reason: ' . ($user->ban_reason ?? 'No reason provided.'));
        }

        Auth::login($user, true);

        return redirect()->intended('/');
    }

    protected function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com', 'throwaway.email', 'guerrillamail.com',
            'mailinator.com', 'tempmail.net', 'fakeinbox.com',
            '10minutemail.com', 'trashmail.com', 'yopmail.com',
        ];

        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, $disposableDomains);
    }
}
