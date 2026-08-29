<?php

namespace App\Models;

use App\Support\ClientAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Distinct visitors on a given day, counted in SQL.
     *
     * 🔴 **Only answers for TODAY, or for the day being aggregated right now.** Fingerprints are
     * cleared by forgetVisitorsUpTo the moment the nightly aggregation has counted them, so asking
     * this about any earlier day returns 0 — not because nobody came, but because the answer has
     * already been written down and the working-out thrown away.
     *
     * ⚠ For any past day, read `analytics_daily.unique_visitors`. It is the same number, kept.
     */
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
        $client = ClientAgent::ours($userAgent);
        if ($client !== null) {
            return $client['kind'];
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
     * A fingerprint that answers one question — "have we seen this visitor today?" — and nothing
     * else, ever again.
     *
     * 🔴 **The salt is random, per day, and thrown away.** It used to be `config('app.key')`: a
     * constant, so anybody holding the database and the `.env` could take an IP address, recompute
     * its hash and confirm the visit — for the ninety days the rows lived. With a salt that exists
     * for two days and is never written down, that check becomes impossible the moment it expires,
     * for us as much as for anybody else. The data stops being pseudonymous and becomes anonymous
     * in fact rather than by promise.
     *
     * ⚠ **Not md5, and not for the usual reason.** SHA-256 is not slow enough to make brute-forcing
     * four billion IPv4 addresses expensive on modern hardware — the salt is what does the work
     * here. What HMAC changes is that the key is no longer the application key, so rotating that
     * can no longer break visitor counting.
     *
     * ⚠ Truncated to 32 characters because the column is 32 wide, and this is not a signature: a
     * collision costs one over-counted visitor on one day.
     *
     * ⚠ If the cache is cleared mid-day, the salt changes and the day's visitors are counted twice.
     * Preferred to the alternative — writing the salt down somewhere permanent would undo the whole
     * point of it.
     */
    public static function generateVisitorHash(string $ip, string $userAgent, string $date): string
    {
        return substr(hash_hmac('sha256', $ip . '|' . $userAgent, self::dailySalt($date)), 0, 32);
    }

    /**
     * The salt for one day, made once and forgotten two days later.
     *
     * ⚠ Two days, not one: the nightly aggregation runs at 2 a.m. on the day AFTER the one it
     * counts, so a salt binned at midnight would be gone before the figure it serves is computed.
     * It outlives its use by a few hours and no more.
     */
    private static function dailySalt(string $date): string
    {
        return Cache::remember(
            "analytics:visitor-salt:{$date}",
            now()->addDays(2),
            fn () => bin2hex(random_bytes(32))
        );
    }

    /**
     * Forget who, once how many is known.
     *
     * 🔴 **The fingerprint has one use and it is over by 2 a.m.** It answers `COUNT(DISTINCT)` for
     * one day; the moment `analytics_daily` holds that number, the column is dead weight — and it
     * used to sit there for ninety days. The rows stay: route, game, referrer, country and device
     * are what the figures are read from, and none of them points at anybody.
     *
     * Same shape as the audit log's retention: purge the column, keep the line.
     */
    public static function forgetVisitorsUpTo(string $date): int
    {
        return static::whereDate('created_at', '<=', $date)
            ->whereNotNull('visitor_hash')
            ->update(['visitor_hash' => null]);
    }
}
