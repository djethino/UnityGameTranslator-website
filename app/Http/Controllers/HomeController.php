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
            // Published translations only. NOT whereNull('parent_id'): a fork keeps its
            // parent for traceability while being a Main of its own lineage, so that
            // filter hid every fork from the storefront — and nowhere else, since the
            // rest of the site asks for visibility.
            ->where('visibility', 'public')
            ->latest()
            ->take(6)
            ->get();

        // Popular games (6) with available target languages
        $popularGames = Game::withCount(['translations' => function ($query) {
                $query->where('visibility', 'public'); // Count published translations, forks included
            }])
            ->whereHas('translations', function ($query) {
                $query->where('visibility', 'public'); // Games with at least one published translation
            })
            ->orderByDesc('translations_count')
            ->take(6)
            ->get();

        // Load distinct target languages for each popular game (Main translations only)
        foreach ($popularGames as $game) {
            $game->target_languages = Translation::where('game_id', $game->id)
                ->where('visibility', 'public')
                ->distinct()
                ->pluck('target_language')
                ->filter()
                ->values()
                ->toArray();
        }

        return view('home', compact('stats', 'latestTranslations', 'popularGames'));
    }
}
