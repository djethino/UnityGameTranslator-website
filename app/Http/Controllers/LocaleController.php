<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocaleController extends Controller
{
    public function switch(Request $request, string $locale)
    {
        $supportedLocales = array_keys(config('locales.supported', []));

        if (!in_array($locale, $supportedLocales)) {
            $locale = config('locales.default', 'en');
        }

        // Save to session for all users
        session(['locale' => $locale]);

        // Save to database for authenticated users
        if (Auth::check()) {
            Auth::user()->update(['locale' => $locale]);
        }

        // Redirect back with the new locale prefix replacing the old one
        // Without this, redirect()->back() keeps the old locale prefix in the URL,
        // and SetLocale gives URL prefix highest priority, ignoring the session change.
        $previousUrl = url()->previous(config('app.url'));
        $redirectUrl = $this->replaceLocaleInUrl($previousUrl, $locale, $supportedLocales);

        return redirect($redirectUrl);
    }

    /**
     * Replace or add the locale prefix in a URL.
     */
    protected function replaceLocaleInUrl(string $url, string $newLocale, array $supportedLocales): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Strip existing locale prefix if present
        $segments = explode('/', ltrim($path, '/'));
        if (!empty($segments[0]) && in_array($segments[0], $supportedLocales)) {
            array_shift($segments);
        }

        // Rebuild path with new locale prefix
        $newPath = '/' . $newLocale . '/' . implode('/', $segments);
        $newPath = rtrim($newPath, '/');

        // Rebuild URL preserving query string
        $baseUrl = rtrim(config('app.url'), '/');
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        return $baseUrl . $newPath . $query;
    }
}
