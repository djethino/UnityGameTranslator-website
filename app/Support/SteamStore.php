<?php

namespace App\Support;

use App\Exceptions\StoreUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every request to Steam's store goes through here: a ceiling of our own, a share for background
 * work, what a refusal means, and the answers kept.
 *
 * 🔴 **A refusal must never happen** (user, 2026-10-05: "il ne faut pas de blocage steam … le
 * 429/403 ça ne devrait jamais arriver"). The store's limit is undocumented — about 200 requests
 * per 5 minutes per address, measured by others since 2015 — and punishes insisting with pauses of
 * minutes to hours. So the site holds ITSELF well under it:
 *
 * - **120 requests per 5 minutes in all**, so the store is never reached even with a neighbour on
 *   the same shared address asking too;
 * - **30 of them at most for background work** (games:rate-adult, Check stores, the change list's
 *   DLC), so a player publishing always has room;
 * - an answer is kept a day (`appKey`), the store's "no such app" included; a change Steam reports
 *   forgets it (App\Services\StoreChanges).
 *
 * When the ceiling is reached, or the store refuses anyway, `take()` says no and the caller is told
 * {@see StoreUnavailable} — never a made-up answer. A refusal is written down; while it stands, one
 * request every 5 minutes is let through as the probe (the store never says it accepts again), and
 * the first success clears it.
 */
final class SteamStore
{
    private const BlockedKey = 'steam:store:refused';
    private const ProbeKey = 'steam:store:probe';
    private const AllKey = 'steam:store:all';
    private const BackgroundKey = 'steam:store:background';

    /** Our ceiling, and the background's share of it, per window. */
    public const Ceiling = 120;
    public const BackgroundShare = 30;
    public const WindowSeconds = 300;

    /** How long an app's answer is kept. A day: a store page moves little, and a change is told. */
    public const AppTtlSeconds = 86400;

    private static bool $background = false;

    /** Run `$work` as background work: it draws on the background share only. */
    public static function inBackground(callable $work): mixed
    {
        $was = self::$background;
        self::$background = true;
        try {
            return $work();
        } finally {
            self::$background = $was;
        }
    }

    /**
     * Take one request from the ceiling, or throw {@see StoreUnavailable}. Called right before
     * every request to the store — never for an answer that is kept.
     */
    public static function take(): void
    {
        if (self::refusing()) {
            // The probe: one request per window, whoever asks.
            if (!Cache::add(self::ProbeKey, true, self::WindowSeconds)) {
                throw new StoreUnavailable('Steam store refusing, probe already sent this window');
            }

            return;
        }

        if (self::$background && RateLimiter::tooManyAttempts(self::BackgroundKey, self::BackgroundShare)) {
            throw new StoreUnavailable('Background share of the Steam ceiling spent');
        }
        if (RateLimiter::tooManyAttempts(self::AllKey, self::Ceiling)) {
            throw new StoreUnavailable('Steam ceiling reached');
        }

        RateLimiter::hit(self::AllKey, self::WindowSeconds);
        if (self::$background) {
            RateLimiter::hit(self::BackgroundKey, self::WindowSeconds);
        }
    }

    /** When the store last refused us, and with what — null when it takes requests. */
    public static function refusal(): ?array
    {
        return Cache::get(self::BlockedKey);
    }

    /** Whether the store is refusing us. */
    public static function refusing(): bool
    {
        return self::refusal() !== null;
    }

    /**
     * Read the answer's status: a refusal is written down and thrown, a success clears the last
     * refusal. Anything else that is not a success is a store that did not answer: thrown too.
     */
    public static function note(int $status): void
    {
        if ($status === 429 || $status === 403) {
            Cache::forever(self::BlockedKey, ['status' => $status, 'at' => Carbon::now()->toIso8601String()]);
            throw new StoreUnavailable("Steam store refused ({$status})");
        }

        if ($status >= 200 && $status < 300) {
            if (self::refusing()) {
                Cache::forget(self::BlockedKey);
                Cache::forget(self::ProbeKey);
            }

            return;
        }

        throw new StoreUnavailable("Steam store answered {$status}");
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
