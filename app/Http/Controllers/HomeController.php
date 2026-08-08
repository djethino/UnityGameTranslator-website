<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;

class HomeController extends Controller
{
    /**
     * Below this net vote count, a translation stops being counted as finished.
     *
     * Strictly below zero, not "few votes": silence is not disagreement, and a translation
     * nobody has voted on has to keep the benefit of the doubt. It takes people actively saying
     * no — more of them than say yes — for the claim to be set aside.
     */
    private const COMPLETE_VOTE_FLOOR = -1;

    public function index()
    {
        // What the project holds so far.
        //
        // Shown from the first day, without a threshold: this is a beta, and a visitor who reads
        // small numbers on a young project reads them as young, not as failing. What would be
        // dishonest is to hide them until they flatter.
        //
        // ONE rule behind every number: published, and holding at least one translated line. The
        // game count used to be every row in the table — it counted a game created by mistake
        // with nothing attached, and two whose only translations are captured text nobody has
        // translated yet. A visitor reads "games" as "games I could play in my language".
        $published = fn () => Translation::query()->where('visibility', 'public')->withTranslatedLines();

        $translations = $published()->count();
        $completed = $published()
            ->where('status', 'complete')
            // Declared by its author, so the claim needs a way of being taken back. Two things
            // take it back: the community disagreeing loudly enough (a net vote below the floor)
            // and a report waiting for a moderator. Neither deletes anything — the translation
            // stays where it is, it simply stops being counted as finished while in doubt.
            ->where('vote_count', '>', self::COMPLETE_VOTE_FLOOR)
            ->whereDoesntHave('reports', fn ($query) => $query->where('status', 'pending'))
            ->count();

        $stats = [
            'games' => Game::whereHas('translations', fn ($query) => $query
                ->where('visibility', 'public')->withTranslatedLines())->count(),
            'translations' => $translations,
            'completed' => $completed,
            // Said outright rather than left to be worked out: "in progress" is the number that
            // invites someone in, and a reader should not have to subtract to find it.
            'in_progress' => max(0, $translations - $completed),
            'users' => User::count(),
            'downloads' => $published()->sum('download_count'),
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
