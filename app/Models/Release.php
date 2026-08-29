<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A version of ours that has actually been published.
 *
 * 🔴 **What we publish is the only non-arbitrary bound on what the usage tables may hold.** A
 * User-Agent is written by whoever is calling, so without this anyone could invent versions — one
 * row per made-up number, in tables that should hold a handful. A row cap was the obvious answer and
 * a bad one: any number is arbitrary, and the day there genuinely were that many versions it would
 * stop recording in silence.
 *
 * 🔴 **A release is never deleted from this table, even when GitHub stops listing it.** Two reasons,
 * and the second was a latent bug: someone may still be running a build we have withdrawn, and
 * dropping it here would file them under `unrecognised` — losing the very copies one needs to see.
 * And the API returns the last 100 releases, so beyond that the oldest simply stop being returned;
 * the previous JSON cache was replaced wholesale on every refresh, which would have quietly demoted
 * every early version the day we ship our hundred-and-first.
 */
class Release extends Model
{
    protected $table = 'releases';

    protected $fillable = ['product', 'version', 'published_at', 'prerelease'];

    protected $casts = [
        'published_at' => 'datetime',
        'prerelease' => 'boolean',
    ];

    /**
     * Every release of a product, newest first, with the ones whose date we never learned at the
     * end.
     *
     * ⚠ A null `published_at` is not "old", it is "unknown" — imported from the cache that only held
     * tag names. Sorting it as if it were a date would put the whole of our history in the wrong
     * place until the next hourly refresh.
     */
    public static function forProduct(string $product): \Illuminate\Support\Collection
    {
        return self::where('product', $product)
            ->orderByRaw('published_at IS NULL')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
    }
}
