<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Translation;
use App\Services\GameSearchService;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request)
    {
        // A game nobody has published a translation for has nothing to offer a page called
        // "Browse Translations" — and it happens: a translation gets withdrawn and leaves its
        // game behind, empty. Its own page stays reachable by URL, where "Be the first to
        // contribute!" is the right thing to say; a card in the catalogue is not.
        // publiclyListed and not just 'public': a file published without a single translated
        // line leaves the catalogue after its grace period, and a game whose only translation is
        // one of those has nothing to offer here either.
        $query = Game::whereHas('translations', fn ($q) => $q->publiclyListed())
            ->withCount('translations')
            ->withSum('translations', 'download_count');

        // Search by game name
        if ($request->filled('q')) {
            $search = $this->escapeLike($request->q);

            // ⚠ The latin handle beside the title, so a game written in another script can be
            // reached from a keyboard. Never displayed — see App\Support\LatinSearch.
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('latin_search', 'like', '%' . mb_strtolower($search) . '%');
            });
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

        // Games somebody has declared finished. What "finished" means, and when the claim is
        // suspended, lives on the model — see Translation::scopeFinished.
        $completedOnly = $request->boolean('completed');
        if ($completedOnly) {
            $query->whereHas('translations', fn ($q) => $q
                ->where('visibility', 'public')->withTranslatedLines()->finished());
        }

        // The language the visitor is most likely looking for: the one they filtered on, then
        // the one they told us they PLAY in, and only failing both the one they are reading the
        // site in.
        //
        // 🔴 **Reading and playing are two different languages**, and this used to know only the
        // first. Someone on an English interface wanting French subtitles was ranked as if they
        // wanted English, and a Tamil player — no interface exists in Tamil — was ranked as if
        // they wanted whatever they had settled for reading. The stored preference is what fixes
        // both; the interface language stays the fallback, so nobody who never sets it sees any
        // change.
        $highlightLanguage = $request->filled('target')
            ? $request->target
            : \App\Services\GameLanguage::name();

        // On by default, and turned off through the URL rather than remembered. A sort order
        // kept in a cookie is invisible: the list comes back in an order the visitor no longer
        // remembers choosing, a shared link shows something else to whoever opens it, and the
        // site would carry one more cookie to declare on its privacy page. The URL says it all.
        $languageFirst = $request->input('lang_first', '1') !== '0';

        // Games available in that language come first — WITHOUT hiding the others, which is
        // what a filter would do. Someone browsing in French wants French translations at the
        // top, not a page that pretends nothing else exists.
        //
        // It is a SORT PREFERENCE, not a filter, and that changes what it has to mean: it must
        // ask its question about the same translations the sort is ranking. Asked about ANY
        // French translation while the list was ordered by what was recently FINISHED, it put
        // games with nothing finished at all above one finished the day before, on the sole
        // ground of being in French — French first, and the sort left saying nothing.
        //
        // So the flag narrows with the sort: on "recently finished" it means "finished IN my
        // language", which reads as the eye expects — French finished first, then finished in
        // other languages, then the rest.
        $languageScope = match ($request->input('sort', 'name')) {
            'finished' => fn ($q) => $q->where('visibility', 'public')->withTranslatedLines()->finished(),
            default => fn ($q) => $q->where('visibility', 'public'),
        };

        if ($highlightLanguage && $languageFirst) {
            $query->withExists(['translations as has_highlight_language' => function ($q) use ($highlightLanguage, $languageScope) {
                $languageScope($q);
                $q->where('target_language', $highlightLanguage);
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
            // Where the front page's finished list continues. Without it, "see more" under a
            // list of recently FINISHED translations landed on games sorted by their own creation
            // date, and the reader had to work out they were looking at something else.
            //
            // No twin for "latest translations": ordering games by their newest translation says
            // almost exactly what "recently updated" already says, and a second near-identical
            // entry in a dropdown informs nobody. That list simply has no "see more" — a filter
            // invented to justify a link is the tail wagging the dog.
            'finished' => $query->withMax(
                ['translations as last_finished' => fn ($q) => $q
                    ->where('visibility', 'public')->withTranslatedLines()->finished()],
                'content_updated_at'
            )->orderByDesc('last_finished'),
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
        // Not just WHICH languages, but how far each has got: a flag alone reads as a promise
        // that the game can be played in that language, and a file of collected-but-untranslated
        // text does not keep it. See Translation::languageStatesForGames.
        $languageStates = Translation::languageStatesForGames($games->pluck('id'));
        $languagesByGame = collect($languageStates)
            ->map(fn ($byLanguage) => collect($byLanguage)->keys()->sort()->values());

        // Get available languages for filters. Public only, same reason as above: a filter
        // offering a language that exists solely in an unpublished branch both announces that
        // work and returns nothing when picked.
        $targetLanguages = Translation::publiclyListed()->distinct()->pluck('target_language')->sort();
        $sourceLanguages = Translation::publiclyListed()->distinct()->pluck('source_language')->sort();

        // Language names in the language itself ("Français", not "French"), like the site's own
        // language switcher: a name shown to someone is written the way they write it.
        $languageNames = config('language-names', []);

        return view('games.index', compact(
            'games',
            'languageNames',
            'targetLanguages',
            'sourceLanguages',
            'languagesByGame',
            'languageStates',
            'highlightLanguage',
            'languageFirst',
            'sort',
            'completedOnly'
        ));
    }

    public function show(Game $game, Request $request)
    {
        // Get ALL translations for this game (we'll group them ourselves).
        // 'parent' is loaded because the default sort reads ranking_score, which reads
        // fork_bonus, which reads $this->parent — one SQL query per fork otherwise, on the
        // most visited page of the site.
        // originAuthor and publicForks are read by every card that has them; loading them here
        // keeps a page of twenty translations at a handful of queries instead of forty.
        $query = $game->translations()->with([
            'user',
            'parent',
            'originAuthor',
            'publicForks.user',
        ]);

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

        // A published file with no translated line leaves this page once its grace period is
        // over — the banner in "my translations" promises exactly that. Rejected on the loaded
        // collection rather than in SQL because the query also carries the BRANCHES, whose
        // existence this page shows on purpose (their content stays private) and which the rule
        // has nothing to say about.
        $allTranslations = $query->orderBy('created_at', 'desc')->get()
            ->reject(fn ($t) => $t->visibility === 'public' && $t->isEmptyPastGrace());

        // And with it, the branches that were hanging from it.
        //
        // A branch has no public standing of its own: this page shows that it EXISTS, under the
        // Main it contributes to, and its content stays private. Left behind alone, the grouping
        // below promoted it to the group's primary — so a branch appeared as the game's
        // translation, with a download button that answers 403. The lineage leaves the page
        // whole, or not at all.
        $listedUuids = $allTranslations->where('visibility', 'public')->pluck('file_uuid')->filter()->flip();
        $allTranslations = $allTranslations
            ->reject(fn ($t) => $t->visibility === 'branch' && !$listedUuids->has($t->file_uuid))
            ->values();

        // How far the furthest translation of this game reaches, asked ONCE. Every card needs it
        // to say what share of the game it covers, and the accessor would otherwise run its own
        // MAX per translation. Taken from the whole game, never from $allTranslations: those are
        // filtered by language, and a French-only view must not shrink the yardstick.
        $gameMaxResolved = Translation::maxResolvedLinesForGame($game->id);

        // How many published translations this game has, for the badge that says nobody has gone
        // further: being furthest is a race of one when there is nobody else, and saying so would
        // dress up a lack of competition as an achievement.
        $publicTranslationCount = Translation::where('game_id', $game->id)
            ->publiclyListed()
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
        // Same order of preference as the list: filtered, then the language they PLAY in, then
        // the one they read the site in. See the note there.
        $highlightLanguage = $request->filled('target')
            ? $request->target
            : \App\Services\GameLanguage::name();
        $languageFirst = $request->input('lang_first', '1') !== '0';

        if ($highlightLanguage && $languageFirst) {
            $groupsCollection = $groupsCollection->sortByDesc(
                fn ($g) => $g['primary']?->target_language === $highlightLanguage ? 1 : 0
            );
        }

        $translationGroups = $groupsCollection->values()->all();

        // The languages this page may ANNOUNCE — in its filters, its title and its description.
        //
        // These were read from every row, visibility included: an unpublished branch made the
        // page promise a language nobody could download, which is precisely what the catalogue
        // refuses to do elsewhere, and a delisted file kept announcing its own after leaving.
        // The page then read "download free community translations of X in Portuguese" above
        // "No translation for this game yet".
        $targetLanguages = $game->translations()->publiclyListed()->distinct()->pluck('target_language')->sort();
        $sourceLanguages = $game->translations()->publiclyListed()->distinct()->pluck('source_language')->sort();

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
            // Same reason as the listing above: typing "longyin" has to reach 龙胤立志传.
            ->orWhere('latin_search', 'like', '%' . mb_strtolower($search) . '%')
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
