<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IndexNow (https://www.indexnow.org/) notifies participating search engines
 * (Bing, Yandex, Seznam, Naver — which also feed DuckDuckGo, Qwant, Ecosia...)
 * that URLs changed, so they recrawl within minutes instead of weeks.
 * Google does not use IndexNow; it relies on the sitemap + Search Console.
 */
class IndexNowService
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /**
     * Submit a game's page (all locale variants) plus the games list.
     */
    public static function submitGame(Game $game): void
    {
        $urls = [
            url('/games/' . $game->slug),
            url('/games'),
        ];
        foreach (array_keys(config('locales.supported', [])) as $locale) {
            $urls[] = url('/' . $locale . '/games/' . $game->slug);
        }

        self::submit($urls);
    }

    /**
     * Submit URLs to IndexNow. No-op when INDEXNOW_KEY is not configured
     * or outside production (search engines reject non-matching hosts anyway).
     */
    public static function submit(array $urls): void
    {
        $key = config('services.indexnow.key');
        if (empty($key) || empty($urls) || !app()->environment('production')) {
            return;
        }

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, [
                'host' => parse_url(config('app.url'), PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => url('/indexnow.txt'),
                'urlList' => array_values(array_unique($urls)),
            ]);

            // 200 = processed, 202 = key validation pending — anything else is a real problem
            if (!in_array($response->status(), [200, 202], true)) {
                Log::warning('IndexNow submission rejected', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('IndexNow submission failed: ' . $e->getMessage());
        }
    }
}
