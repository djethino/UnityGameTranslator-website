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
        $urlLocale = self::localeInPath($request);

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
     * The locale we support that this code names, or null.
     *
     * ⚠ Every comparison against our own list goes through here, and the reason is a bug this
     * replaced: the list was searched with in_array() on a lowercased tag. That worked only as
     * long as every locale code WAS lowercase. The moment one carried a region — 'pt-PT' — a
     * browser announcing exactly that stopped matching it, and Portugal would have been handed
     * Brazilian Portuguese: the precise outcome the split exists to prevent. A locale code is
     * case-insensitive by BCP 47; comparing it as a byte string is the mistake.
     *
     * Aliases are applied here too, so a legacy code resolves identically wherever it arrives
     * from — URL, stored preference, session or browser header.
     */
    public static function resolve(?string $tag): ?string
    {
        if ($tag === null || trim($tag) === '') {
            return null;
        }

        $wanted = strtolower(str_replace('_', '-', trim($tag)));

        foreach (array_keys(config('locales.supported', [])) as $locale) {
            if (strtolower($locale) === $wanted) {
                return $locale;
            }
        }

        foreach (config('locales.aliases', []) as $alias => $target) {
            if (strtolower($alias) === $wanted) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Get locale from URL prefix (e.g., /en/games, /fr/docs)
     *
     * ⚠ Public and static because a second question depends on the same answer: whether this URL
     * can be cached at all. A page reached WITHOUT a prefix is rendered in whatever language the
     * session holds, so it must never be stored by a shared cache — see PublicCacheHeaders. Two
     * copies of "does the path start with a locale?" would be two chances to disagree.
     */
    public static function localeInPath(Request $request): ?string
    {
        $supportedLocales = array_keys(config('locales.supported', []));
        $segment = $request->segment(1);

        if (!$segment) {
            return null;
        }

        // ⚠ Resolved WITHOUT aliases on purpose: an alias in the path is answered with a 301 to
        // the real one (see routes/web.php) rather than served here. Two URLs quietly returning
        // the same page is what splits a page's ranking between them.
        foreach ($supportedLocales as $locale) {
            if (strcasecmp($segment, $locale) === 0) {
                return $locale;
            }
        }

        return null;
    }

    protected function detectLocale(Request $request, array $supportedLocales): string
    {
        $defaultLocale = config('locales.default', 'en');

        // 1. Check authenticated user preference
        //    Resolved rather than compared: an account that chose Portuguese before the two were
        //    told apart holds 'pt', and dropping it would silently reset a stated preference to
        //    whatever the browser happens to say.
        if (Auth::check() && Auth::user()->locale) {
            $userLocale = self::resolve(Auth::user()->locale);
            if ($userLocale !== null) {
                return $userLocale;
            }
        }

        // 2. Check session
        if (session()->has('locale')) {
            $sessionLocale = self::resolve(session('locale'));
            if ($sessionLocale !== null) {
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
            // ordinary case without opening the catalogue at all. ⚠ It must run BEFORE the
            // catalogue, because the catalogue shortens a tag one segment at a time — it would
            // turn 'pt-PT' into the language 'pt' and lose the very distinction being asked for.
            $direct = self::resolve($tag);
            if ($direct !== null) {
                return $direct;
            }

            $canonical = self::resolve(CatalogStore::canonicalTag($tag));
            if ($canonical !== null) {
                return $canonical;
            }
        }

        return null;
    }
}
