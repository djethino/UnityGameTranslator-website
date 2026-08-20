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
     *  - `UnityGameTranslator/1.0`                      → mod, null (every build up to 2026-08-20)
     *  - `UnityGameTranslatorManager/0.1.0`             → manager, 0.1.0
     *
     * ⚠ The slash matters: `UnityGameTranslatorManager/` starts with `UnityGameTranslator`, so the
     * Manager pattern is tested first and the mod pattern requires the slash straight after.
     */
    public static function detectClient(?string $userAgent): ?array
    {
        $agent = trim((string) $userAgent);

        if (preg_match('#^UnityGameTranslatorManager/(\S+)#', $agent, $m) === 1) {
            return ['product' => 'manager', 'version' => $m[1], 'variant' => null];
        }

        if (preg_match('#^UnityGameTranslator(?:-Mod)?/(\S+)(?:\s+\(([^)]+)\))?#', $agent, $m) === 1) {
            // "1.0" is not a version, it is the placeholder every build sent before versions
            // existed. Recorded as unknown so it cannot be mistaken for a real 1.0 release later.
            $version = $m[1] === '1.0' ? null : $m[1];

            return [
                'product' => 'mod',
                'version' => $version,
                'variant' => $m[2] ?? null,
            ];
        }

        return null;
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
