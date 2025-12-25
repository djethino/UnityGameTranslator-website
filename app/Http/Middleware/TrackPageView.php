<?php

namespace App\Http\Middleware;

use App\Models\AnalyticsEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackPageView
{
    /**
     * Routes to track (only public pages, not API or admin)
     */
    protected array $trackedRoutes = [
        'home',
        'games.index',
        'games.show',
        'docs',
        'login',
        'translations.create',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Wrap everything in try-catch - analytics should NEVER break the site
        try {
            // Only track GET requests with successful responses
            if ($request->method() !== 'GET') {
                return $response;
            }

            // Safe status code check
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($statusCode >= 400) {
                return $response;
            }

            // Only track specific routes
            $routeName = $request->route()?->getName();
            if (!$routeName || !in_array($routeName, $this->trackedRoutes)) {
                return $response;
            }

            // Don't track bots
            $userAgent = $request->userAgent() ?? '';
            if ($this->isBot($userAgent)) {
                return $response;
            }

            $this->trackEvent($request, $routeName);
        } catch (\Throwable $e) {
            // Silently fail - analytics should never break the site
            report($e);
        }

        return $response;
    }

    /**
     * Track the page view event
     */
    protected function trackEvent(Request $request, string $routeName): void
    {
        $userAgent = $request->userAgent() ?? '';
        $ip = $request->ip() ?? '0.0.0.0';
        $today = now()->toDateString();

        // Get game_id if viewing a game page
        $gameId = null;
        if ($routeName === 'games.show') {
            $game = $request->route('game');
            $gameId = is_object($game) ? $game->id : $game;
        }

        // Detect country from Accept-Language header (simple approximation)
        $country = $this->detectCountryFromLanguage($request->header('Accept-Language'));

        AnalyticsEvent::create([
            'route' => $routeName,
            'game_id' => $gameId,
            'country' => $country,
            'referrer_domain' => AnalyticsEvent::extractReferrerDomain($request->header('Referer')),
            'device' => AnalyticsEvent::detectDevice($userAgent),
            'browser' => AnalyticsEvent::detectBrowser($userAgent),
            'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $userAgent, $today),
            'created_at' => now(),
        ]);
    }

    /**
     * Detect if request is from a bot
     */
    protected function isBot(string $userAgent): bool
    {
        $bots = [
            'bot', 'crawler', 'spider', 'slurp', 'googlebot', 'bingbot',
            'yandex', 'baidu', 'duckduckbot', 'facebookexternalhit',
            'twitterbot', 'linkedinbot', 'pinterest', 'semrush',
            'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot',
        ];

        $userAgent = strtolower($userAgent);

        foreach ($bots as $bot) {
            if (str_contains($userAgent, $bot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple country detection from Accept-Language header
     */
    protected function detectCountryFromLanguage(?string $acceptLanguage): ?string
    {
        if (empty($acceptLanguage)) {
            return null;
        }

        // Map common language codes to countries
        $languageToCountry = [
            'en-US' => 'US', 'en-GB' => 'GB', 'en-AU' => 'AU', 'en-CA' => 'CA',
            'fr-FR' => 'FR', 'fr-CA' => 'CA', 'fr-BE' => 'BE', 'fr-CH' => 'CH',
            'de-DE' => 'DE', 'de-AT' => 'AT', 'de-CH' => 'CH',
            'es-ES' => 'ES', 'es-MX' => 'MX', 'es-AR' => 'AR',
            'it-IT' => 'IT',
            'pt-BR' => 'BR', 'pt-PT' => 'PT',
            'ru-RU' => 'RU',
            'ja-JP' => 'JP', 'ja' => 'JP',
            'ko-KR' => 'KR', 'ko' => 'KR',
            'zh-CN' => 'CN', 'zh-TW' => 'TW', 'zh' => 'CN',
            'pl-PL' => 'PL', 'pl' => 'PL',
            'tr-TR' => 'TR', 'tr' => 'TR',
            'nl-NL' => 'NL', 'nl-BE' => 'BE',
            'sv-SE' => 'SE', 'da-DK' => 'DK', 'no-NO' => 'NO', 'fi-FI' => 'FI',
        ];

        // Parse Accept-Language and find first match
        $languages = explode(',', $acceptLanguage);
        foreach ($languages as $lang) {
            $lang = trim(explode(';', $lang)[0]); // Remove quality value

            if (isset($languageToCountry[$lang])) {
                return $languageToCountry[$lang];
            }

            // Try without region
            $langShort = explode('-', $lang)[0];
            $fallback = [
                'en' => 'US', 'fr' => 'FR', 'de' => 'DE', 'es' => 'ES',
                'it' => 'IT', 'pt' => 'PT', 'ru' => 'RU', 'nl' => 'NL',
            ];
            if (isset($fallback[$langShort])) {
                return $fallback[$langShort];
            }
        }

        return null;
    }
}
