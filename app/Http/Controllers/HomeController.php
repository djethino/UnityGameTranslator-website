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

        // Latest translations (6) - Only Main translations (exclude branches)
        $latestTranslations = Translation::with(['game', 'user'])
            ->whereNull('parent_id') // Only Main translations, no branches
            ->latest()
            ->take(6)
            ->get();

        // Popular games (6) with available target languages
        $popularGames = Game::withCount(['translations' => function ($query) {
                $query->whereNull('parent_id'); // Count only Main translations
            }])
            ->whereHas('translations', function ($query) {
                $query->whereNull('parent_id'); // Only games with at least one Main translation
            })
            ->orderByDesc('translations_count')
            ->take(6)
            ->get();

        // Load distinct target languages for each popular game (Main translations only)
        foreach ($popularGames as $game) {
            $game->target_languages = Translation::where('game_id', $game->id)
                ->whereNull('parent_id')
                ->distinct()
                ->pluck('target_language')
                ->filter()
                ->values()
                ->toArray();
        }

        return view('home', compact('stats', 'latestTranslations', 'popularGames'));
    }
}
