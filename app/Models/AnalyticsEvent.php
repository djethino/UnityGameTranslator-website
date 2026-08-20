<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_events';

    protected $fillable = [
        'route',
        'game_id',
        'country',
        'referrer_domain',
        'device',
        'browser',
        'visitor_hash',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * What can be written in `device` — where a view or a download came from.
     *
     * 🔴 **This list lives here and not in the database.** It used to be an enum, and the day the
     * API download started writing `'mod'` every one of those inserts threw and was swallowed:
     * months of downloads recorded nowhere, and an analytics screen that only knew about website
     * visitors. What can call this site is not a settled list — the Manager arrives beside the mod
     * — so it belongs where adding to it is a one-line change, not a migration that fails silently.
     *
     * ⚠ `mod` and `manager` are not device types, and that is deliberate: this column answers
     * "what was on the other end", and for a program the useful answer is which program.
     */
    public const DEVICES = ['desktop', 'mobile', 'tablet', 'mod', 'manager'];

    /** The two of these that are our own software, shown apart from browser traffic. */
    public const OUR_CLIENTS = ['mod', 'manager'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * How one day's events break down over a column, as [value => count],
     * biggest first.
     *
     * Counted by the database, never hydrated into models: a busy day holds
     * hundreds of thousands of rows, and grouping them in PHP costs more the
     * more the site succeeds — the one kind of slowdown that shows up exactly
     * when it hurts. Used by the nightly aggregation and by the admin page for
     * the day it cannot read from the aggregates yet.
     */
    public static function breakdownFor(string $date, string $column, ?int $limit = null): array
    {
        $query = self::whereDate('created_at', $date)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select($column, \Illuminate\Support\Facades\DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('total', $column)
            ->map(fn($count) => (int) $count)
            ->toArray();
    }

    /** Distinct visitors on a given day, counted in SQL. */
    public static function uniqueVisitorsOn(string $date): int
    {
        return self::whereDate('created_at', $date)
            ->distinct('visitor_hash')
            ->count('visitor_hash');
    }

    /**
     * Parse User-Agent to detect device type
     *
     * ⚠ Our own programs are recognised first: a mod is not a desktop, and calling it one is how
     * the mod's traffic used to disappear into the browser figures.
     */
    public static function detectDevice(string $userAgent): string
    {
        $client = self::detectClient($userAgent);
        if ($client !== null) {
            return $client['product'];
        }

        $userAgent = strtolower($userAgent);

        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/(mobile|iphone|ipod|android|blackberry|opera mini|iemobile)/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Which of our programs is calling, which version, and which build of it.
     *
     * 🔴 **This is what makes two decisions answerable that were pure guesswork.** Whether an old
     * release is still out there in numbers — and therefore whether compression can be turned on
     * for JSON without cutting those installs off — and whether a mod loader adapter is still worth
     * maintaining. Nothing measured either, because the mod called itself the bare literal
     * `UnityGameTranslator/1.0` on every build ever shipped until 2026-08-20.
     *
     * ⚠ **Coarse on purpose, and that is the privacy argument.** A product, a version and, for the
     * mod, which loader it runs under: a handful of values across the whole population, and nothing
     * that separates one installation from another. No identifier is derived from this, and the
     * caller's address is never stored anywhere.
     *
     *  - `UnityGameTranslator/0.11.1 (BepInEx6-IL2CPP)` → mod, 0.11.1, BepInEx6-IL2CPP
     *  - `UnityGameTranslator/1.0`                      → mod, legacy (every build up to 2026-08-20)
     *  - `UnityGameTranslatorManager/0.1.0`             → manager, 0.1.0
     *
     * 🔴 **A build from before is recognised by the ABSENCE of the loader, not by the number.**
     * Testing for the literal "1.0" would work until the mod actually reaches 1.0 — and that
     * release would then be filed among the builds that cannot decompress, which is the one row
     * that decides whether JSON compression can be turned on. Versions carry three components
     * (`0.11.0`), so a real v1 will be `1.0.0`, but relying on that is relying on a convention
     * nothing enforces. The parenthesis is the thing that changed on 2026-08-20.
     *
     * ⚠ The slash matters: `UnityGameTranslatorManager/` starts with `UnityGameTranslator`, so the
     * Manager pattern is tested first and the mod pattern requires the slash straight after.
     */
    public static function detectClient(?string $userAgent): ?array
    {
        $agent = trim((string) $userAgent);

        if (preg_match('#^UnityGameTranslatorManager/(\S+)#', $agent, $m) === 1) {
            return [
                'product' => 'manager',
                'version' => self::cleanVersion($m[1]),
                'variant' => null,
                'legacy' => false,
            ];
        }

        if (preg_match('#^UnityGameTranslator(?:-Mod)?/(\S+)(?:\s+\(([^)]+)\))?#', $agent, $m) === 1) {
            $loader = self::cleanVariant($m[2] ?? null);

            return [
                'product' => 'mod',
                'version' => self::cleanVersion($m[1]),
                'variant' => $loader,
                // No loader named = a build published before the User-Agent carried one, i.e. one
                // that asks for gzip and cannot read it.
                'legacy' => ($m[2] ?? null) === null,
            ];
        }

        return null;
    }

    /**
     * 🔴 **A User-Agent is written by whoever is calling, so none of it is trusted.**
     *
     * Anyone can send `UnityGameTranslator/<script>alert(1)</script> (AAAA…)` and, without this,
     * it lands in a table and then on an admin screen. Blade escapes it, so this is not about
     * script injection — it is about a stranger choosing what our own measurements say, and about
     * a table whose row count they control.
     *
     * Anything not shaped like a version becomes null: "we do not know", which is true, rather
     * than a value invented by the caller. The count of distinct rows is bounded separately, in
     * ClientUsageDaily.
     */
    private static function cleanVersion(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return preg_match('/^\d{1,4}(\.\d{1,4}){0,3}(-[A-Za-z0-9.]{1,12})?$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * The mod loader, in the shape the adapters actually report ("BepInEx6-IL2CPP",
     * "MelonLoader-Mono"). Deliberately a shape and not a fixed list: a new adapter must not need
     * a website deployment to be counted. What stops the shape from being abused is the row
     * ceiling in ClientUsageDaily, not this.
     */
    private static function cleanVariant(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,23}$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * Parse User-Agent to detect browser
     */
    public static function detectBrowser(string $userAgent): string
    {
        if (str_contains($userAgent, 'Firefox')) {
            return 'Firefox';
        }
        if (str_contains($userAgent, 'Edg')) {
            return 'Edge';
        }
        if (str_contains($userAgent, 'Chrome')) {
            return 'Chrome';
        }
        if (str_contains($userAgent, 'Safari')) {
            return 'Safari';
        }
        if (str_contains($userAgent, 'Opera') || str_contains($userAgent, 'OPR')) {
            return 'Opera';
        }

        return 'Other';
    }

    /**
     * Extract domain from referrer URL
     */
    public static function extractReferrerDomain(?string $referrer): ?string
    {
        if (empty($referrer)) {
            return null;
        }

        $parsed = parse_url($referrer);
        $host = $parsed['host'] ?? null;

        if (!$host) {
            return null;
        }

        // Remove www.
        $host = preg_replace('/^www\./', '', $host);

        // Ignore self-referrals
        if (str_contains($host, 'unitygametranslator')) {
            return null;
        }

        return substr($host, 0, 100);
    }

    /**
     * Generate a visitor hash (for unique visitor counting, no IP stored)
     */
    public static function generateVisitorHash(string $ip, string $userAgent, string $date): string
    {
        // Hash IP + UA + date = same visitor on same day = same hash
        // IP is never stored, only the hash
        return md5($ip . '|' . $userAgent . '|' . $date . '|' . config('app.key'));
    }
}
