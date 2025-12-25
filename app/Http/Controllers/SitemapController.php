<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $games = Game::withCount('translations')
            ->having('translations_count', '>', 0)
            ->orderBy('updated_at', 'desc')
            ->get();

        $content = view('sitemap', compact('games'))->render();

        return response($content, 200)
            ->header('Content-Type', 'application/xml');
    }
}
