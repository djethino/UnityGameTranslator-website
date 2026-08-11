<?php

namespace App\Http\Middleware;

use App\Services\CatalogStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * Locale priority:
     * 1. URL prefix (e.g., /en/, /fr/) - explicit, always respected
     * 2. User preference (if authenticated)
     * 3. Session (if previously set)
     * 4. Browser Accept-Language header
     * 5. Default locale (English)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('locales.supported', []));

        // Check if locale is in URL prefix (highest priority - explicit choice)
        $urlLocale = $this->getLocaleFromUrl($request, $supportedLocales);

        if ($urlLocale) {
            // URL has explicit locale - use it and update session
            App::setLocale($urlLocale);
            session(['locale' => $urlLocale]);

            // Remove {locale} from route parameters so it's not passed positionally
            // to controllers that don't expect it. Without this, Laravel would pass
            // the locale string as the first controller argument, causing a TypeError
            // when the controller expects a route-bound model like Game.
            if ($request->route()) {
                $request->route()->forgetParameter('locale');
            }
        } else {
            // No URL prefix - detect from other sources
            $locale = $this->detectLocale($request, $supportedLocales);
            App::setLocale($locale);

            // Store in session for guests
            if (!Auth::check()) {
                session(['locale' => $locale]);
            }
        }

        return $next($request);
    }

    /**
     * Get locale from URL prefix (e.g., /en/games, /fr/docs)
     */
    protected function getLocaleFromUrl(Request $request, array $supportedLocales): ?string
    {
        $segment = $request->segment(1);

        if ($segment && in_array($segment, $supportedLocales)) {
            return $segment;
        }

        return null;
    }

    protected function detectLocale(Request $request, array $supportedLocales): string
    {
        $defaultLocale = config('locales.default', 'en');

        // 1. Check authenticated user preference
        if (Auth::check() && Auth::user()->locale) {
            $userLocale = Auth::user()->locale;
            if (in_array($userLocale, $supportedLocales)) {
                return $userLocale;
            }
        }

        // 2. Check session
        if (session()->has('locale')) {
            $sessionLocale = session('locale');
            if (in_array($sessionLocale, $supportedLocales)) {
                return $sessionLocale;
            }
        }

        // 3. Check browser Accept-Language header
        $browserLocale = $this->getBrowserLocale($request, $supportedLocales);
        if ($browserLocale) {
            return $browserLocale;
        }

        // 4. Default
        return $defaultLocale;
    }

    /**
     * The interface language a browser is asking for, or null to leave the decision to the default.
     *
     * ⚠ THE LOCALE IS RESOLVED THROUGH THE CATALOGUE, not by taking its first two letters. That
     * shortcut was wrong in both directions:
     *
     *   · "zh-Hant-TW" became "zh", and this site's zh IS Simplified Chinese — so a machine set to
     *     Traditional was served the other script, silently;
     *   · "iw" stayed "iw" and matched nothing, so browsers and runtimes still emitting the old
     *     code for Hebrew got English while `he` sat right there in the list.
     *
     * The catalogue knows every code a language answers to (zh-Hans, zh-CN, iw, no…) and shortens
     * one segment at a time rather than by character count, which is what keeps zh-Hant from
     * collapsing into zh. Same rule as the mod's Languages.FromLocale, on purpose: one machine must
     * not be understood differently by the site and by the plugin running in its games.
     *
     * ⚠ A language the interface does not have falls through to the DEFAULT, which is English —
     * deliberately, and documented in the catalogue's own `about.interface_fallback`. Routing
     * Catalan to Spanish or Traditional Chinese to Simplified would often be more useful and is
     * refused on purpose: it decides for somebody what else they read. Do not "improve" this.
     */
    protected function getBrowserLocale(Request $request, array $supportedLocales): ?string
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (!$acceptLanguage) {
            return null;
        }

        // Parse Accept-Language (e.g. "fr-FR,fr;q=0.9,en;q=0.8"), keeping the FULL tag: the
        // subtags are the information, and dropping them here is what caused the bug above.
        $requested = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $part = trim($part);
            $priority = 1.0;

            if (strpos($part, ';q=') !== false) {
                [$part, $q] = explode(';q=', $part);
                $priority = (float) $q;
                $part = trim($part);
            }

            if ($part !== '' && !isset($requested[$part])) {
                $requested[$part] = $priority;
            }
        }

        arsort($requested);

        foreach (array_keys($requested) as $tag) {
            // An exact hit on one of our own locale codes first: it costs nothing and covers the
            // ordinary case without opening the catalogue at all.
            $plain = strtolower($tag);
            if (in_array($plain, $supportedLocales, true)) {
                return $plain;
            }

            $canonical = CatalogStore::canonicalTag($tag);
            if ($canonical !== null && in_array($canonical, $supportedLocales, true)) {
                return $canonical;
            }
        }

        return null;
    }
}
