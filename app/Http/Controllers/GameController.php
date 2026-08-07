<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Translation;
use App\Services\GameSearchService;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Escape LIKE wildcards to prevent SQL injection via wildcard abuse
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    public function index(Request $request)
    {
        $query = Game::withCount('translations')
            ->withSum('translations', 'download_count');

        // Search by game name
        if ($request->filled('q')) {
            $search = $this->escapeLike($request->q);
            $query->where('name', 'like', '%' . $search . '%');
        }

        // Filter by target language
        if ($request->filled('target')) {
            $query->whereHas('translations', function ($q) use ($request) {
                $q->where('target_language', $request->target);
            });
        }

        // Filter by source language
        if ($request->filled('source')) {
            $query->whereHas('translations', function ($q) use ($request) {
                $q->where('source_language', $request->source);
            });
        }

        // The language the visitor is most likely looking for: the one they filtered on, or
        // failing that the one they are reading the site in.
        $highlightLanguage = $request->filled('target')
            ? $request->target
            : (config('locales.supported.' . app()->getLocale() . '.name') ?: null);

        // On by default, and turned off through the URL rather than remembered. A sort order
        // kept in a cookie is invisible: the list comes back in an order the visitor no longer
        // remembers choosing, a shared link shows something else to whoever opens it, and the
        // site would carry one more cookie to declare on its privacy page. The URL says it all.
        $languageFirst = $request->input('lang_first', '1') !== '0';

        // Games available in that language come first — WITHOUT hiding the others, which is
        // what a filter would do. Someone browsing in French wants French translations at the
        // top, not a page that pretends nothing else exists.
        if ($highlightLanguage && $languageFirst) {
            $query->withExists(['translations as has_highlight_language' => function ($q) use ($highlightLanguage) {
                $q->where('visibility', 'public')->where('target_language', $highlightLanguage);
            }])->orderByDesc('has_highlight_language');
        }

        // Sorting. "Updated" reads content_updated_at, not updated_at: a vote or a download
        // must not float a translation back to the top of a "recently updated" list.
        $sort = $request->input('sort', 'name');
        match ($sort) {
            'downloads' => $query->orderByDesc('translations_sum_download_count'),
            'translations' => $query->orderByDesc('translations_count'),
            'new' => $query->orderByDesc('created_at'),
            'updated' => $query->withMax(
                ['translations as last_content_update' => fn ($q) => $q->where('visibility', 'public')],
                'content_updated_at'
            )->orderByDesc('last_content_update'),
            default => null,
        };

        // Name last, always: it breaks ties in a way anyone can predict
        $games = $query->orderBy('name')->paginate(24)->withQueryString();

        // Which languages each listed game is available in — the first thing anyone browsing
        // this page wants to know, and the card only said HOW MANY translations existed.
        //
        // One query for the whole page rather than one per card, and PUBLIC translations only:
        // a branch is someone's unpublished contribution, and listing its language here would
        // announce work its author has not published.
        $languagesByGame = Translation::whereIn('game_id', $games->pluck('id'))
            ->where('visibility', 'public')
            ->select('game_id', 'target_language')
            ->distinct()
            ->get()
            ->groupBy('game_id')
            ->map(fn ($rows) => $rows->pluck('target_language')->unique()->sort()->values());

        // Get available languages for filters. Public only, same reason as above: a filter
        // offering a language that exists solely in an unpublished branch both announces that
        // work and returns nothing when picked.
        $targetLanguages = Translation::where('visibility', 'public')->distinct()->pluck('target_language')->sort();
        $sourceLanguages = Translation::where('visibility', 'public')->distinct()->pluck('source_language')->sort();

        // Language names in the language itself ("Français", not "French"), like the site's own
        // language switcher: a name shown to someone is written the way they write it.
        $languageNames = config('language-names', []);

        return view('games.index', compact(
            'games',
            'languageNames',
            'targetLanguages',
            'sourceLanguages',
            'languagesByGame',
            'highlightLanguage',
            'languageFirst',
            'sort'
        ));
    }

    public function show(Game $game, Request $request)
    {
        // Get ALL translations for this game (we'll group them ourselves).
        // 'parent' is loaded because the default sort reads ranking_score, which reads
        // fork_bonus, which reads $this->parent — one SQL query per fork otherwise, on the
        // most visited page of the site.
        $query = $game->translations()->with(['user', 'parent']);

        // Filter by target language
        if ($request->filled('target')) {
            $query->where('target_language', $request->target);
        }

        // Filter by source language
        if ($request->filled('source')) {
            $query->where('source_language', $request->source);
        }

        // Note: type filter removed - type is now a computed accessor from HVASM stats
        // and cannot be filtered at the database level

        $allTranslations = $query->orderBy('created_at', 'desc')->get();

        // How far the furthest translation of this game reaches, asked ONCE. Every card needs it
        // to say what share of the game it covers, and the accessor would otherwise run its own
        // MAX per translation. Taken from the whole game, never from $allTranslations: those are
        // filtered by language, and a French-only view must not shrink the yardstick.
        $gameMaxResolved = Translation::maxResolvedLinesForGame($game->id);

        // How many published translations this game has, for the badge that says nobody has gone
        // further: being furthest is a race of one when there is nobody else, and saying so would
        // dress up a lack of competition as an achievement.
        $publicTranslationCount = Translation::where('game_id', $game->id)
            ->where('visibility', 'public')
            ->count();
        foreach ($allTranslations as $t) {
            $t->gameMaxHint = $gameMaxResolved;
        }

        // Group translations by file_uuid
        // Structure: [uuid => [primary, versions[], forks[]]]
        $translationGroups = [];

        foreach ($allTranslations as $translation) {
            $uuid = $translation->file_uuid;

            if (!$uuid) {
                // No UUID = standalone translation (legacy or manual)
                $translationGroups['standalone_' . $translation->id] = [
                    'primary' => $translation,
                    'versions' => collect(),
                    'forks' => collect(),
                    'total_downloads' => $translation->download_count,
                    'best_vote' => $translation->vote_count,
                ];
                continue;
            }

            if (!isset($translationGroups[$uuid])) {
                $translationGroups[$uuid] = [
                    'primary' => null,
                    'versions' => collect(),
                    'forks' => collect(),
                    'original_author_id' => null,
                    'total_downloads' => 0,
                    'best_vote' => 0,
                ];
            }

            $group = &$translationGroups[$uuid];
            $group['total_downloads'] += $translation->download_count;

            // Determine original author (first uploader)
            if ($group['original_author_id'] === null) {
                // Find the oldest translation with this UUID to determine original author
                $oldest = $allTranslations->where('file_uuid', $uuid)->sortBy('created_at')->first();
                $group['original_author_id'] = $oldest->user_id;
            }

            // Categorize: version (same author) or fork (different author)
            if ((int) $translation->user_id === (int) $group['original_author_id']) {
                // Same author = version
                $group['versions']->push($translation);
            } else {
                // Different author = fork
                $group['forks']->push($translation);
            }
        }

        // For each group, select the primary (best) translation
        foreach ($translationGroups as $uuid => &$group) {
            if (str_starts_with($uuid, 'standalone_')) {
                continue; // Already set
            }

            // Sort versions by vote_count desc, then by created_at desc
            $group['versions'] = $group['versions']->sortByDesc(function ($t) {
                return [$t->vote_count, $t->created_at->timestamp];
            })->values();

            // Primary = best voted version from original author
            $group['primary'] = $group['versions']->first();
            $group['best_vote'] = $group['primary']?->vote_count ?? 0;

            // Sort forks by vote_count desc
            $group['forks'] = $group['forks']->sortByDesc('vote_count')->values();
        }
        unset($group);

        // Sort groups by the specified sort option
        $sort = $request->get('sort', 'score');
        $groupsCollection = collect($translationGroups);

        $groupsCollection = match ($sort) {
            // "Quality" now means usefulness: how much of the game is covered, lifted by how much
            // of it was reviewed. On the old 0-3 average, two hundred lines polished to the last
            // comma outranked four thousand at sixty per cent — the opposite of what someone
            // about to play the game needs.
            'quality' => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->usefulness() ?? 0),
            'votes' => $groupsCollection->sortByDesc(fn($g) => $g['best_vote']),
            'lines' => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->line_count ?? 0),
            'downloads' => $groupsCollection->sortByDesc(fn($g) => $g['total_downloads']),
            'date' => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->created_at ?? now()),
            default => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->ranking_score ?? 0), // score (default)
        };

        // The visitor's language first — same rule as the game list, and the same reason: a
        // translation into a language they do not read is not a candidate, however good it is.
        // Applied AFTER the chosen sort and never instead of it: PHP sorts are stable, so the
        // order picked above still decides between translations of the same language.
        $highlightLanguage = $request->filled('target')
            ? $request->target
            : (config('locales.supported.' . app()->getLocale() . '.name') ?: null);
        $languageFirst = $request->input('lang_first', '1') !== '0';

        if ($highlightLanguage && $languageFirst) {
            $groupsCollection = $groupsCollection->sortByDesc(
                fn ($g) => $g['primary']?->target_language === $highlightLanguage ? 1 : 0
            );
        }

        $translationGroups = $groupsCollection->values()->all();

        // Get available languages for this game
        $targetLanguages = $game->translations()->distinct()->pluck('target_language')->sort();
        $sourceLanguages = $game->translations()->distinct()->pluck('source_language')->sort();

        return view('games.show', compact('gameMaxResolved', 'publicTranslationCount',
            'game', 'translationGroups', 'targetLanguages', 'sourceLanguages',
            'highlightLanguage',
            'languageFirst'));
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $search = $this->escapeLike($query);
        $games = Game::where('name', 'like', '%' . $search . '%')
            ->limit(10)
            ->get(['id', 'name', 'slug']);

        return response()->json($games);
    }

    /**
     * Search external game APIs
     * Full flow: local DB → Steam (if steam_id) → IGDB → RAWG
     * Supports: ?q=name or ?steam_id=xxx for exact lookup
     */
    public function searchExternal(Request $request, GameSearchService $gameService)
    {
        // Steam ID exact lookup (from mod's _game metadata)
        if ($request->filled('steam_id')) {
            $steamId = $request->get('steam_id');

            // Use findBySteamId: local DB → Steam API
            $game = $gameService->findBySteamId($steamId);
            if ($game) {
                $game['auto_detected'] = true;
                return response()->json([$game]);
            }

            // Not found by steam_id
            return response()->json([]);
        }

        // Name search: use searchFull for complete flow (local → Steam → IGDB → RAWG)
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        // searchFull handles: local DB first, then external APIs, with deduplication
        $results = $gameService->searchFull($query, null, 10);

        return response()->json($results);
    }
}
