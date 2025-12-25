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
