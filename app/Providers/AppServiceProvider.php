<?php

namespace App\Providers;

use App\Models\Report;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Steam\SteamExtendSocialite;
use SocialiteProviders\Twitch\TwitchExtendSocialite;
use Voval\Socialite\EpicGames\EpicGamesExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
        // Register Socialite providers
        Event::listen(SocialiteWasCalled::class, DiscordExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, TwitchExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, SteamExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, EpicGamesExtendSocialite::class.'@handle');

        // Share pending reports count with layout for admin badge
        View::composer('layouts.app', function ($view) {
            $pendingReportsCount = 0;
            if (Auth::check() && Auth::user()->isAdmin()) {
                $pendingReportsCount = Report::where('status', 'pending')->count();
            }
            $view->with('pendingReportsCount', $pendingReportsCount);
        });

        // Register Blade directive for language flags (SVG via flag-icons CSS)
        Blade::directive('langflag', function ($expression) {
            return "<?php \$__code = config('language-flags')[$expression] ?? null; echo \$__code ? '<span class=\"fi fi-' . e(\$__code) . '\"></span>' : '🌐'; ?>";
        });
    }

    /**
     * Get flag emoji for a language name
     */
    public static function getLanguageFlag(string $language): string
    {
        return config('language-flags')[$language] ?? '🌐';
    }
}
