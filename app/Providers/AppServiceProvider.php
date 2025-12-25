<?php

namespace App\Providers;

use App\Models\Report;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Twitch\TwitchExtendSocialite;

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
        // Register Socialite providers (Discord, Twitch)
        Event::listen(SocialiteWasCalled::class, DiscordExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, TwitchExtendSocialite::class.'@handle');

        // Share pending reports count with layout for admin badge
        View::composer('layouts.app', function ($view) {
            $pendingReportsCount = 0;
            if (Auth::check() && Auth::user()->isAdmin()) {
                $pendingReportsCount = Report::where('status', 'pending')->count();
            }
            $view->with('pendingReportsCount', $pendingReportsCount);
        });
    }
}
