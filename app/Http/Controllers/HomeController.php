<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;

class HomeController extends Controller
{
    public function index()
    {
        // Statistics for homepage
        $stats = [
            'games' => Game::count(),
            'translations' => Translation::count(),
            'users' => User::count(),
        ];

        // Latest translations (6)
        $latestTranslations = Translation::with(['game', 'user'])
            ->latest()
            ->take(6)
            ->get();

        // Popular games (6)
        $popularGames = Game::withCount('translations')
            ->orderByDesc('translations_count')
            ->take(6)
            ->get();

        return view('home', compact('stats', 'latestTranslations', 'popularGames'));
    }
}
