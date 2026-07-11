<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'link',
        'show_banner',
        'created_by',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'show_banner' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->published_at->isPast()
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The most recent active banner announcement, cached briefly
     * (rendered in the layout on every page, guests included).
     */
    public static function currentBanner(): ?self
    {
        return Cache::remember('announcement.banner', 60, function () {
            return self::where('show_banner', true)
                ->where('published_at', '<=', now())
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('published_at')
                ->first();
        }) ?: null;
    }

    public static function clearBannerCache(): void
    {
        Cache::forget('announcement.banner');
    }
}
