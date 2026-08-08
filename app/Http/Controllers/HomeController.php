<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;

class HomeController extends Controller
{
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
        // What "finished" means, and when the claim is suspended, lives on the model:
        // Translation::scopeFinished
        $completed = $published()->finished()->count();

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

        // Two lists, and they PARTITION the same set rather than overlapping: what is finished,
        // and what is under way. The counters above say 2 and 18; a visitor who then found the
        // same translation in both lists would have to work out that the sections are not
        // exclusive. Finished first, because it is the one thing here that can be played tonight.
        //
        // Three each, not six: a front page is a doorway. What does not fit is one click away,
        // and the catalogue already sorts the same two ways these lists do.
        $finished = Translation::with(['game', 'user'])
            ->where('visibility', 'public')
            ->withTranslatedLines()
            ->finished()
            // When it was last actually worked on, not when it was first published: a translation
            // declared finished years ago and touched last week is the more recent piece of news.
            // Never updated_at, which a vote or a download moves.
            ->orderByDesc('content_updated_at')
            ->orderByDesc('created_at')
            ->take(3)
            ->get();

        // Published translations only. NOT whereNull('parent_id'): a fork keeps its parent for
        // traceability while being a Main of its own lineage, so that filter hid every fork from
        // the storefront — and nowhere else, since the rest of the site asks for visibility.
        $latestTranslations = Translation::with(['game', 'user'])
            ->where('visibility', 'public')
            // A shop window shows work, and a file with no translated line has none to show yet.
            // Nothing is hidden from its author — the grace period keeps it in the listings and
            // in their own screens — but the front page is where it has least business being.
            ->withTranslatedLines()
            ->whereKeyNot($finished->modelKeys() ?: [0])
            ->latest()
            ->take(3)
            ->get();

        // Popular means downloaded, not translated the most times.
        //
        // The section was ordered by how many translations a game had, which is a measure of the
        // community's effort rather than of the game's draw: a game with four modest translations
        // came ahead of one downloaded twice as often with a single good one. Measured before
        // changing it, because a ranking built on empty counters ranks noise — but no game sits at
        // zero, the median is 46 and the leader holds under a quarter of the total, so downloads
        // do discriminate here.
        //
        // Both facts stay on the card: the ranking is by downloads, the translation count is
        // written beside it, so the order can be read rather than guessed.
        $popularGames = Game::withCount(['translations' => function ($query) {
                // Published translations that hold something, forks included
                $query->where('visibility', 'public')->withTranslatedLines();
            }])
            ->withSum(['translations as downloads_total' => function ($query) {
                $query->where('visibility', 'public')->withTranslatedLines();
            }], 'download_count')
            ->whereHas('translations', function ($query) {
                $query->where('visibility', 'public')->withTranslatedLines();
            })
            ->orderByDesc('downloads_total')
            // A tie goes to the game people have worked on more: equal draw, more hands on it
            ->orderByDesc('translations_count')
            ->take(3)
            ->get();

        // Which languages each game has, and how far each has got. A flag alone promises the
        // game can be played in that language; the state keeps the promise honest — and lets a
        // collected-but-untranslated language be shown in grey rather than hidden, which says
        // the true thing: somebody has started, and the next person could pick it up.
        $languageStates = Translation::languageStatesForGames($popularGames->pluck('id'));
        foreach ($popularGames as $game) {
            $game->language_states = $languageStates[$game->id] ?? [];
        }

        return view('home', compact('stats', 'finished', 'latestTranslations', 'popularGames'));
    }
}
