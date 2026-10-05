<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the site knows about Steam's store refusing it, and the store answers it keeps.
 *
 * 🔴 **The store's limit is per IP address, undocumented, and punishes insisting** (2026-10-05,
 * analyse/retention-et-mesures.md): about 200 requests per 5 minutes, measured by others since
 * 2015; past it, 429 then 403, for minutes and up to hours when a caller keeps going. On a shared
 * host the address is shared too. So:
 *
 * - a refusal (429 or 403) is WRITTEN DOWN, and the background work (games:rate-adult, Check
 *   stores) stops at once on it — a player publishing has the quota first;
 * - a success clears it: that is the only signal that the store takes requests again — it never
 *   says so itself;
 * - an app's answer is kept a day (`app()`): the same page was asked again for every publication,
 *   search and check. A change Steam reports (IStoreService/GetAppList) forgets it first.
 */
final class SteamStore
{
    private const BlockedKey = 'steam:store:refused';

    /** How long an app's answer is kept. A day: a store page moves little, and a change is told. */
    public const AppTtlSeconds = 86400;

    /** When the store last refused us, and with what — null when it takes requests. */
    public static function refusal(): ?array
    {
        return Cache::get(self::BlockedKey);
    }

    /** Whether background work must leave the store alone. */
    public static function refusing(): bool
    {
        return self::refusal() !== null;
    }

    /** Read the answer's status: a refusal is written down, a success clears the last one. */
    public static function note(int $status): void
    {
        if ($status === 429 || $status === 403) {
            Cache::forever(self::BlockedKey, ['status' => $status, 'at' => Carbon::now()->toIso8601String()]);
        } elseif ($status >= 200 && $status < 300 && self::refusing()) {
            Cache::forget(self::BlockedKey);
        }
    }

    public static function appKey(string $appId): string
    {
        return 'steam:app:' . $appId;
    }

    /** Forget an app's kept answer — Steam said it changed. */
    public static function forget(string $appId): void
    {
        Cache::forget(self::appKey($appId));
    }
}
