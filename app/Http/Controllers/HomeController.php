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
            // A shop window shows work, and a file with no translated line has none to show yet.
            // Nothing is hidden from its author — the grace period keeps it in the listings and
            // in their own screens — but the front page is where it has least business being.
            ->withTranslatedLines()
            ->latest()
            ->take(6)
            ->get();

        // Popular games (6) with available target languages
        $popularGames = Game::withCount(['translations' => function ($query) {
                // Published translations that hold something, forks included
                $query->where('visibility', 'public')->withTranslatedLines();
            }])
            ->whereHas('translations', function ($query) {
                $query->where('visibility', 'public')->withTranslatedLines();
            })
            ->orderByDesc('translations_count')
            ->take(6)
            ->get();

        // Load distinct target languages for each popular game (Main translations only)
        foreach ($popularGames as $game) {
            // Only languages someone has actually translated INTO. A flag on a card is a promise
            // that the game can be played in that language; an empty capture file makes none.
            $game->target_languages = Translation::where('game_id', $game->id)
                ->where('visibility', 'public')
                ->withTranslatedLines()
                ->distinct()
                ->pluck('target_language')
                ->filter()
                ->values()
                ->toArray();
        }

        return view('home', compact('stats', 'latestTranslations', 'popularGames'));
    }
}
