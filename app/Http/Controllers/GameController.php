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

        $games = $query->orderBy('name')->paginate(24);

        // Get available languages for filters
        $targetLanguages = Translation::distinct()->pluck('target_language')->sort();
        $sourceLanguages = Translation::distinct()->pluck('source_language')->sort();

        return view('games.index', compact('games', 'targetLanguages', 'sourceLanguages'));
    }

    public function show(Game $game, Request $request)
    {
        // Get ALL translations for this game (we'll group them ourselves)
        $query = $game->translations()->with('user');

        // Filter by target language
        if ($request->filled('target')) {
            $query->where('target_language', $request->target);
        }

        // Filter by source language
        if ($request->filled('source')) {
            $query->where('source_language', $request->source);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $allTranslations = $query->orderBy('created_at', 'desc')->get();

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
        $sort = $request->get('sort', 'votes');
        $groupsCollection = collect($translationGroups);

        $groupsCollection = match ($sort) {
            'lines' => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->line_count ?? 0),
            'downloads' => $groupsCollection->sortByDesc(fn($g) => $g['total_downloads']),
            'date' => $groupsCollection->sortByDesc(fn($g) => $g['primary']?->created_at ?? now()),
            default => $groupsCollection->sortByDesc(fn($g) => $g['best_vote']),
        };

        $translationGroups = $groupsCollection->values()->all();

        // Get available languages for this game
        $targetLanguages = $game->translations()->distinct()->pluck('target_language')->sort();
        $sourceLanguages = $game->translations()->distinct()->pluck('source_language')->sort();

        return view('games.show', compact('game', 'translationGroups', 'targetLanguages', 'sourceLanguages'));
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
