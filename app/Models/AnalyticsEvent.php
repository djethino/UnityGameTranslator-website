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
     */
    public static function detectDevice(string $userAgent): string
    {
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
     * Generate a visitor hash (for unique visitor counting, no IP stored)
     */
    public static function generateVisitorHash(string $ip, string $userAgent, string $date): string
    {
        // Hash IP + UA + date = same visitor on same day = same hash
        // IP is never stored, only the hash
        return md5($ip . '|' . $userAgent . '|' . $date . '|' . config('app.key'));
    }
}
