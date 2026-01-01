<?php

namespace App\Http\Middleware;

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

    protected function getBrowserLocale(Request $request, array $supportedLocales): ?string
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (!$acceptLanguage) {
            return null;
        }

        // Parse Accept-Language header (e.g., "fr-FR,fr;q=0.9,en;q=0.8")
        $languages = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $part = trim($part);
            $priority = 1.0;

            if (strpos($part, ';q=') !== false) {
                [$part, $q] = explode(';q=', $part);
                $priority = (float) $q;
            }

            // Get the primary language code (e.g., "fr" from "fr-FR")
            $lang = strtolower(substr($part, 0, 2));
            $languages[$lang] = $priority;
        }

        // Sort by priority
        arsort($languages);

        // Find first supported language
        foreach (array_keys($languages) as $lang) {
            if (in_array($lang, $supportedLocales)) {
                return $lang;
            }
        }

        return null;
    }
}
