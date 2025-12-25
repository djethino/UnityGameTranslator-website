<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameSearchService
{
    private ?string $twitchToken = null;

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

            $response = Http::withHeaders([
                'Client-ID' => $clientId,
                'Authorization' => 'Bearer ' . $token,
            ])->withBody(
                "search \"{$query}\"; fields id,name,cover.url; limit {$limit};",
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
