<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each translation's game was identified — what the publishing machine READ, and what the
 * person CHOSE in the publish list.
 *
 * 🔴 **On the translation, not on the game** (decided 2026-10-01: "ça ne peut pas être dans game,
 * c'est lié à la traduction"). One card can hold a translation published from a Steam copy whose id
 * was read on disk and another filed there by a wrong pick; the card cannot say which is which, its
 * translations can. Until now only `game_id` was kept, so a translation under the wrong game could
 * be traced back to its cause by deduction alone.
 *
 * - `game_read`: `{product_name, company_name, steam_id, steam_id_from, engine}` as the client
 *   read them in the game's own files — a fact about one installation, re-written at each upload
 *   of that row (the latest installation it was sent from).
 * - `game_pick`: `{source, id}` — the publish list's answer the person took, kept from the upload
 *   that created the row.
 *
 * ⚠ Nullable and empty for every row written before, and for every client that does not send
 * them: absent is "not said", never "nothing was read".
 *
 * ⚠ No personal data: a product name, a studio, a store id — what any copy of the game carries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->json('game_read')->nullable()->after('game_id');
            $table->json('game_pick')->nullable()->after('game_read');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['game_read', 'game_pick']);
        });
    }
};
