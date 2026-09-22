<?php

namespace App\Support;

/**
 * Where a store shows a game — the links an admin follows to check an id before trusting it.
 *
 * ⚠ These addresses are built from, or taken from, what a store answered, and they end up in an
 * `href`. So each one is held to the shape it must have — digits for a Steam id, the IGDB host for
 * an IGDB page — and anything else yields no link rather than a link somewhere unexpected.
 */
final class StoreLinks
{
    /**
     * The Steam store page of an app id, or null when the id is not one.
     */
    public static function steam(?string $appId): ?string
    {
        return $appId !== null && ctype_digit($appId)
            ? 'https://store.steampowered.com/app/' . $appId . '/'
            : null;
    }

    /**
     * An IGDB page as IGDB itself gave it (`url` on a game), or null when it is not one. An IGDB
     * page is addressed by a slug, so an id alone cannot be turned into one.
     */
    public static function igdb(?string $url): ?string
    {
        return $url !== null && str_starts_with($url, 'https://www.igdb.com/games/') ? $url : null;
    }

    /**
     * An image to open full size: only an https address, which is all a store ever serves.
     */
    public static function image(?string $url): ?string
    {
        return $url !== null && str_starts_with($url, 'https://') ? $url : null;
    }
}
