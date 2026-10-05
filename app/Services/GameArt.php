<?php

namespace App\Services;

/**
 * Which picture a game card shows, and which wide one its page is shared with.
 *
 * 🔴 **Chosen by SHAPE, from the card's own ids** (user, 2026-10-06, analyse/images-des-jeux.md).
 * A card used to keep the picture of whichever store it was created from: a Steam pick gave the
 * 460×215 header, an IGDB pick the portrait cover — the catalogue was a mix of both, cropped into
 * portrait frames. Every card is now offered, in this order:
 *
 * | rank | picture | shape |
 * |---|---|---|
 * | 0 | Steam library capsule (by `steam_id`) | portrait, by Steam's own format |
 * | 1 | IGDB cover (by `igdb_id`) | portrait when IGDB says so (its width and height) |
 * | 2 | Steam store header | wide |
 * | 3 | an IGDB cover that is wider than tall | wide |
 *
 * The first portrait wins; with none, the first wide one, which every page shows whole on its own
 * blur (resources/js/game-cover.js). Nothing here is found by a title: each picture is read from
 * an id the card already holds, so it is the picture of that game — the line drawn on 2026-09-22
 * between what is written automatically and what an admin accepts.
 *
 * ⚠ The banner (`banner_url`) is the Steam header whatever the cover: the wide picture a link to
 * the game's page is previewed with (`og:image`), where a portrait one would be cropped.
 */
class GameArt
{
    public const SteamCover = 0;
    public const IgdbCover = 1;
    public const SteamBanner = 2;
    public const IgdbWide = 3;

    /** What a card's current picture ranks when it is none of the candidates — always replaced. */
    private const Unranked = 4;

    public function __construct(private GameSearchService $stores)
    {
    }

    /**
     * Every picture the card's ids give, best first — `[{url, source, shape, rank}]`.
     *
     * ⚠ Throws StoreUnavailable when Steam could not be asked: a choice made without Steam's
     * answer would take a lower picture for the best one. IGDB not answering leaves its cover out,
     * which can only make the choice keep what is there (see shouldReplace).
     */
    public function candidates(?string $steamId, int|string|null $igdbId): array
    {
        return $this->read($steamId, $igdbId)['candidates'];
    }

    /**
     * What the card's ids say, in the same two calls: its pictures (`candidates`, best first) and
     * the names each store gives the game (`names` — Steam's, then IGDB's), which become its other
     * names when they are not its title (Game::otherNames, user 2026-10-06: 侠影录 on Steam is
     * "Legacy of Shadows" on IGDB).
     *
     * ⚠ Throws StoreUnavailable when Steam could not be asked — see candidates().
     *
     * @return array{candidates: list<array>, names: list<string>}
     */
    public function read(?string $steamId, int|string|null $igdbId): array
    {
        $found = [];
        $names = [];

        if ($steamId) {
            $assets = $this->stores->steamAssets((string) $steamId);
            if ($assets['name'] ?? null) {
                $names[] = $assets['name'];
            }
            if ($assets['cover'] ?? null) {
                $found[] = ['url' => $assets['cover'], 'source' => 'steam', 'shape' => 'portrait', 'rank' => self::SteamCover];
            }
            if ($assets['banner'] ?? null) {
                $found[] = ['url' => $assets['banner'], 'source' => 'steam', 'shape' => 'wide', 'rank' => self::SteamBanner];
            }
        }

        if ($igdbId !== null && $igdbId !== '' && ctype_digit((string) $igdbId)) {
            $igdb = $this->stores->igdbFacts((int) $igdbId);
            if ($igdb['name'] ?? null) {
                $names[] = $igdb['name'];
            }
            $cover = $igdb['cover'] ?? null;
            if ($cover) {
                $portrait = $cover['height'] > $cover['width'];
                $found[] = [
                    'url' => $cover['url'],
                    'source' => 'igdb',
                    'shape' => $portrait ? 'portrait' : 'wide',
                    'rank' => $portrait ? self::IgdbCover : self::IgdbWide,
                ];
            }
        }

        usort($found, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return ['candidates' => $found, 'names' => $names];
    }

    /** The picture a card should show among these, or null when there is none. */
    public static function best(array $candidates): ?array
    {
        return $candidates[0] ?? null;
    }

    /** The wide picture a link to the card's page is previewed with, or null. */
    public static function banner(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate['rank'] === self::SteamBanner) {
                return $candidate['url'];
            }
        }

        return null;
    }

    /**
     * Should the picture a card shows give way to the best one found?
     *
     * 🔴 **Only for a better one, never a sideways move.** Asked twice, the stores do not always
     * answer the same — IGDB can be silent for a moment — and "always take the first candidate"
     * would flip a card between pictures from one check to the next. So the current picture is
     * ranked too: by the candidate it is, or, when it is none of them (an older address of the same
     * art, a RAWG screenshot), by what its address says it is. It is replaced when the best ranks
     * higher — or when it is the same kind of picture from the same store at a new address, which is
     * the store having renewed that art.
     */
    public static function shouldReplace(?string $current, ?array $best, array $candidates): bool
    {
        if ($best === null || $current === $best['url']) {
            return false;
        }

        if ($current === null || $current === '') {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['url'] === $current) {
                return $best['rank'] < $candidate['rank'];
            }
        }

        $currentRank = self::rankOfAddress($current);

        return $best['rank'] < $currentRank
            || ($best['rank'] === $currentRank && $currentRank !== self::Unranked);
    }

    /**
     * What a picture's address says it is, for a picture no store offered this time. ⚠ An IGDB
     * address is ranked a portrait cover: it can only be on a card because a store gave it, and
     * IGDB serves covers under it — the rare wide one is then kept rather than swapped sideways.
     */
    private static function rankOfAddress(string $url): int
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === 'shared.akamai.steamstatic.com' || $host === 'cdn.akamai.steamstatic.com') {
            return preg_match('~/(library_600x900|library_capsule)[^/]*$~', $path) ? self::SteamCover : self::SteamBanner;
        }

        if ($host === 'images.igdb.com') {
            return self::IgdbCover;
        }

        return self::Unranked;
    }
}
