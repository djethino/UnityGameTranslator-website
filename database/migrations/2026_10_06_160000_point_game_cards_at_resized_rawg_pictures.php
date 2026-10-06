<?php

use App\Support\StoreLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A card made from a RAWG pick kept RAWG's ORIGINAL picture — up to 2746×1531 and 910 KB — and
 * handed it to the site's pages and to the mod, which decodes it on the game's thread (2026-10-06:
 * the game froze while the publish list filled). New cards get the resized address
 * (StoreLinks::rawgResized); this points the existing ones at it.
 *
 * ⚠ Only the address changes, through the query builder: no model event, no timestamp — the
 * picture is the same, smaller.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')
            ->where('image_url', 'like', 'https://media.rawg.io/media/%')
            ->orderBy('id')
            ->get(['id', 'image_url'])
            ->each(function ($game) {
                $resized = StoreLinks::rawgResized($game->image_url);
                if ($resized !== $game->image_url) {
                    DB::table('games')->where('id', $game->id)->update(['image_url' => $resized]);
                }
            });
    }

    public function down(): void
    {
        // The originals are one prefix away and nothing needs them back.
    }
};
