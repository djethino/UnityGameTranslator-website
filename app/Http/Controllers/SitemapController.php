<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $content = Cache::remember('sitemap', 3600, function () {
            $games = Game::withCount('translations')
                ->having('translations_count', '>', 0)
                ->orderBy('updated_at', 'desc')
                ->get();

            return view('sitemap', compact('games'))->render();
        });

        return response($content, 200)
            ->header('Content-Type', 'application/xml');
    }
}
