<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Sitemap index - lists all sub-sitemaps
     */
    public function index(): Response
    {
        $content = Cache::remember('sitemap-index', 3600, function () {
            return view('sitemaps.index')->render();
        });

        return response($content, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Static pages sitemap (home, docs, legal, etc.)
     */
    public function pages(): Response
    {
        $content = Cache::remember('sitemap-pages', 3600, function () {
            return view('sitemaps.pages')->render();
        });

        return response($content, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Games sitemap - can be paginated if needed
     */
    public function games(int $page = 1): Response
    {
        $perPage = 1000; // 1000 games × 14 locales = 14,000 URLs max per file

        $cacheKey = "sitemap-games-{$page}";
        $content = Cache::remember($cacheKey, 3600, function () use ($page, $perPage) {
            $games = Game::has('translations')
                ->orderBy('updated_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            if ($games->isEmpty() && $page > 1) {
                return null;
            }

            return view('sitemaps.games', compact('games'))->render();
        });

        if ($content === null) {
            abort(404);
        }

        return response($content, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Get total number of game sitemap pages needed
     */
    public static function getGameSitemapPages(): int
    {
        $perPage = 1000;
        $totalGames = Cache::remember('sitemap-games-count', 3600, function () {
            return Game::has('translations')->count();
        });

        return max(1, ceil($totalGames / $perPage));
    }
}
