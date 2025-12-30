<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameSearchService
{
    private ?string $twitchToken = null;

    /**
     * Full search: local DB first, then Steam (if ID), then external APIs.
     * Optimizes API quota by checking local database first.
     *
     * Order:
     * 1. Local database (games we already have)
     * 2. Steam API (if steam_id provided)
     * 3. IGDB (Twitch) → RAWG (only if not enough local results)
     *
     * @param string|null $query Search query (game name)
     * @param string|null $steamId Steam App ID for exact match
     * @param int $limit Maximum results to return
     * @return array Deduplicated game results
     */
    public function searchFull(?string $query, ?string $steamId = null, int $limit = 15): array
    {
        $results = [];

        // 1. Check local database FIRST (saves API quota)
        if ($query && strlen($query) >= 2) {
            $localGames = $this->searchLocal($query, 5);
            foreach ($localGames as $game) {
                $results[] = $game;
            }
        }

        // 2. Search by Steam ID if provided
        if ($steamId) {
            // First check if we have it locally
            $localBySteam = Game::where('steam_id', $steamId)
                ->withCount('translations')
                ->first();
            if ($localBySteam) {
                // Add at beginning if not already present
                $steamResult = [
                    'id' => $localBySteam->id,
                    'name' => $localBySteam->name,
                    'steam_id' => $localBySteam->steam_id,
                    'image_url' => $localBySteam->image_url,
                    'source' => 'local',
                    'translations_count' => $localBySteam->translations_count,
                ];
                array_unshift($results, $steamResult);
            } else {
                // Call Steam API
                $steamResult = $this->getGameFromSteam($steamId);
                if ($steamResult) {
                    array_unshift($results, $steamResult);
                }
            }
        }

        // 3. Call IGDB if not enough results (IGDB is free)
        if (count($results) < 3 && $query && strlen($query) >= 2) {
            $igdbResults = $this->searchIGDB($query, 10);
            $results = array_merge($results, $igdbResults);
        }

        // 4. Call RAWG only if still no results (RAWG has paid quota)
        if (empty($results) && $query && strlen($query) >= 2) {
            $rawgResults = $this->searchRAWG($query, 10);
            $results = array_merge($results, $rawgResults);
        }

        // Deduplicate by name (case-insensitive)
        $results = $this->deduplicateResults($results);

        // Calculate match_score for each result
        $results = $this->calculateMatchScores($results, $query, $steamId);

        // Sort by match_score descending (best matches first)
        usort($results, fn($a, $b) => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

        return array_slice($results, 0, $limit);
    }

    /**
     * Search local database for games
     */
    public function searchLocal(string $query, int $limit = 5): array
    {
        $search = str_replace(['%', '_'], ['\\%', '\\_'], $query);

        return Game::where('name', 'like', '%' . $search . '%')
            ->withCount('translations')
            ->limit($limit)
            ->get()
            ->map(function ($game) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'steam_id' => $game->steam_id,
                    'image_url' => $game->image_url,
                    'source' => 'local',
                    'translations_count' => $game->translations_count,
                ];
            })
            ->toArray();
    }

    /**
     * Deduplicate results by name (case-insensitive)
     * Prioritizes earlier entries (local > steam > external)
     */
    private function deduplicateResults(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $game) {
            $key = strtolower($game['name'] ?? '');
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $game;
            }
        }

        return $unique;
    }

    /**
     * Calculate match_score for each result based on query and steam_id.
     * Higher score = better match.
     *
     * Scoring:
     * - Steam ID exact match: +50 (very high confidence)
     * - Local source: +20 (game exists in our DB)
     * - Has translations: +1 per translation (max +10)
     * - Exact name match: +20
     * - Partial name match (contains query): +5
     */
    private function calculateMatchScores(array $results, ?string $query, ?string $steamId): array
    {
        $normalizedQuery = $query ? strtolower(trim($query)) : null;

        foreach ($results as &$result) {
            $score = 0;
            $resultName = strtolower($result['name'] ?? '');
            $resultSteamId = $result['steam_id'] ?? null;

            // Steam ID exact match = very high confidence
            if ($steamId && $resultSteamId && $resultSteamId === $steamId) {
                $score += 50;
            }

            // Local source = game exists in our database
            if (($result['source'] ?? '') === 'local') {
                $score += 20;
                // Bonus for having translations (max +10)
                $translationsCount = $result['translations_count'] ?? 0;
                $score += min(10, $translationsCount);
            }

            // Steam API source = verified game info
            if (($result['source'] ?? '') === 'steam') {
                $score += 10;
            }

            // Name matching
            if ($normalizedQuery) {
                if ($resultName === $normalizedQuery) {
                    // Exact match
                    $score += 20;
                } elseif (str_contains($resultName, $normalizedQuery)) {
                    // Partial match
                    $score += 5;
                }
            }

            $result['match_score'] = $score;
        }

        return $results;
    }

    /**
     * Search for games using IGDB first, fallback to RAWG
     */
    public function search(string $query, int $limit = 10): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        // Try IGDB first
        $results = $this->searchIGDB($query, $limit);

        // Fallback to RAWG if no results or error
        if (empty($results)) {
            $results = $this->searchRAWG($query, $limit);
        }

        return $results;
    }

    /**
     * Escape special characters for IGDB query language
     * Prevents injection attacks via search queries
     */
    private function escapeIGDBQuery(string $query): string
    {
        // Remove characters that could break out of the search string or inject commands
        // IGDB uses a custom query language where ; ends statements and " ends strings
        return str_replace(['"', ';', '\\', '/'], ['', '', '', ''], $query);
    }

    /**
     * Search IGDB (Twitch) API
     */
    private function searchIGDB(string $query, int $limit): array
    {
        try {
            $token = $this->getTwitchToken();
            if (!$token) {
                return [];
            }

            $clientId = config('services.twitch.client_id');

            // Escape query to prevent IGDB query injection
            $safeQuery = $this->escapeIGDBQuery($query);
            // Ensure limit is a valid integer
            $safeLimit = max(1, min(50, (int) $limit));

            $response = Http::withHeaders([
                'Client-ID' => $clientId,
                'Authorization' => 'Bearer ' . $token,
            ])->withBody(
                "search \"{$safeQuery}\"; fields id,name,cover.url; limit {$safeLimit};",
                'text/plain'
            )->post('https://api.igdb.com/v4/games');

            if (!$response->successful()) {
                Log::warning('IGDB API error', ['status' => $response->status()]);
                return [];
            }

            $games = $response->json();

            return collect($games)->map(function ($game) {
                $imageUrl = null;
                if (isset($game['cover']['url'])) {
                    // Convert thumbnail to larger image
                    $imageUrl = str_replace('t_thumb', 't_cover_big', $game['cover']['url']);
                    $imageUrl = 'https:' . $imageUrl;
                }

                return [
                    'id' => $game['id'],
                    'name' => $game['name'],
                    'image_url' => $imageUrl,
                    'source' => 'igdb',
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::error('IGDB search error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search RAWG API (fallback)
     */
    private function searchRAWG(string $query, int $limit): array
    {
        try {
            $apiKey = config('services.rawg.key');
            if (!$apiKey) {
                return [];
            }

            $response = Http::get('https://api.rawg.io/api/games', [
                'key' => $apiKey,
                'search' => $query,
                'page_size' => $limit,
            ]);

            if (!$response->successful()) {
                Log::warning('RAWG API error', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();

            return collect($data['results'] ?? [])->map(function ($game) {
                return [
                    'id' => $game['id'],
                    'name' => $game['name'],
                    'image_url' => $game['background_image'] ?? null,
                    'source' => 'rawg',
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::error('RAWG search error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get Twitch OAuth token for IGDB API
     */
    private function getTwitchToken(): ?string
    {
        // Check cache first
        $cached = Cache::get('twitch_api_token');
        if ($cached) {
            return $cached;
        }

        try {
            $clientId = config('services.twitch.client_id');
            $clientSecret = config('services.twitch.client_secret');

            if (!$clientId || !$clientSecret) {
                return null;
            }

            $response = Http::asForm()->post('https://id.twitch.tv/oauth2/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            if (!$response->successful()) {
                Log::error('Twitch token error', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $token = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 3600;

            // Cache token (expire 1 hour before actual expiry)
            Cache::put('twitch_api_token', $token, $expiresIn - 3600);

            return $token;

        } catch (\Exception $e) {
            Log::error('Twitch token error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find a game by Steam ID (local DB first, then Steam API)
     * Used for auto-detection from mod's _game.steam_id metadata
     */
    public function findBySteamId(string $steamId): ?array
    {
        // Check local database first
        $localGame = Game::where('steam_id', $steamId)->first();
        if ($localGame) {
            return [
                'id' => $localGame->igdb_id ?? $localGame->rawg_id ?? $localGame->id,
                'name' => $localGame->name,
                'steam_id' => $localGame->steam_id,
                'image_url' => $localGame->image_url,
                'source' => 'local',
                'local_id' => $localGame->id,
            ];
        }

        // Try Steam API
        return $this->getGameFromSteam($steamId);
    }

    /**
     * Get game details from Steam by Steam App ID
     */
    public function getGameFromSteam(string $steamId): ?array
    {
        try {
            $response = Http::timeout(5)->get('https://store.steampowered.com/api/appdetails', [
                'appids' => $steamId,
            ]);

            if (!$response->successful()) {
                Log::warning('Steam API error', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();

            // Steam returns {steamId: {success: bool, data: {...}}}
            if (!isset($data[$steamId]['success']) || !$data[$steamId]['success']) {
                return null;
            }

            $game = $data[$steamId]['data'];

            return [
                'name' => $game['name'] ?? null,
                'steam_id' => $steamId,
                'image_url' => $game['header_image'] ?? null,
                'source' => 'steam',
            ];

        } catch (\Exception $e) {
            Log::warning('Steam API error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Search for a game by name, trying Steam first (if steam_id provided), then IGDB, then RAWG
     */
    public function findGame(?string $steamId, string $gameName): ?array
    {
        // Try Steam API first if we have a Steam ID
        if ($steamId) {
            $result = $this->getGameFromSteam($steamId);
            if ($result) {
                return $result;
            }
        }

        // Try IGDB search
        $results = $this->searchIGDB($gameName, 1);
        if (!empty($results)) {
            return $results[0];
        }

        // Fallback to RAWG
        $results = $this->searchRAWG($gameName, 1);
        if (!empty($results)) {
            return $results[0];
        }

        return null;
    }

    /**
     * Get game details by ID from the appropriate source
     */
    public function getGame(int $id, string $source): ?array
    {
        if ($source === 'igdb') {
            return $this->getGameFromIGDB($id);
        }

        return $this->getGameFromRAWG($id);
    }

    private function getGameFromIGDB(int $id): ?array
    {
        try {
            $token = $this->getTwitchToken();
            if (!$token) {
                return null;
            }

            $clientId = config('services.twitch.client_id');

            $response = Http::withHeaders([
                'Client-ID' => $clientId,
                'Authorization' => 'Bearer ' . $token,
            ])->withBody(
                "where id = {$id}; fields id,name,cover.url;",
                'text/plain'
            )->post('https://api.igdb.com/v4/games');

            if (!$response->successful() || empty($response->json())) {
                return null;
            }

            $game = $response->json()[0];
            $imageUrl = null;
            if (isset($game['cover']['url'])) {
                $imageUrl = 'https:' . str_replace('t_thumb', 't_cover_big', $game['cover']['url']);
            }

            return [
                'id' => $game['id'],
                'name' => $game['name'],
                'image_url' => $imageUrl,
                'source' => 'igdb',
            ];

        } catch (\Exception $e) {
            Log::error('IGDB get game error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getGameFromRAWG(int $id): ?array
    {
        try {
            $apiKey = config('services.rawg.key');
            if (!$apiKey) {
                return null;
            }

            $response = Http::get("https://api.rawg.io/api/games/{$id}", [
                'key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $game = $response->json();

            return [
                'id' => $game['id'],
                'name' => $game['name'],
                'image_url' => $game['background_image'] ?? null,
                'source' => 'rawg',
            ];

        } catch (\Exception $e) {
            Log::error('RAWG get game error', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
