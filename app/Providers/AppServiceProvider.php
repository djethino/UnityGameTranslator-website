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
        // 🔴 How long "keep me signed in" lasts, set in ONE place because it is a policy and not a
        // detail of whichever controller happens to sign somebody in.
        //
        // Laravel's own default is five years, which is not a decision anybody took — it is simply
        // what the framework ships. OWASP puts a persistent sign-in at fourteen to thirty days for
        // an application of this kind, on the condition that it can be revoked server-side; that
        // condition is now met by the Linked devices screen, so the number can be met too.
        Auth::guard('web')->setRememberDuration(43200); // 30 days

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

        // 🔴 **Flags we draw, not flags we borrow.** This used to emit a `fi fi-xx` span from
        // flag-icons and a hand-written 90-line map in config/language-flags.php — the last
        // hand-written language list on the site, the same anti-pattern as config/languages.php.
        //
        // It now renders the shared component, which reads catalogs/flags.json: one source for the
        // site, the mod and the manager, no icon licence to carry, and the tag chip appearing by
        // itself wherever one flag stands for several languages.
        //
        // ⚠ Kept as a directive rather than replaced in the ten templates that call it: swapping
        // the body switches them all at once, where ten edits are ten chances to miss one.
        Blade::directive('langflag', function ($expression) {
            return "<?php echo view('components.language-mark', ['language' => $expression])->render(); ?>";
        });
    }
}
