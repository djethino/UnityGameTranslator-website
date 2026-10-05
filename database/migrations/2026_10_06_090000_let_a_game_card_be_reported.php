<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report can be about a GAME card, not only a translation (user, 2026-10-05: "ce qui pourrait
 * être bien … c'est qu'on ait un report sur game"). The same table, the same screen: a report is a
 * report, whatever it is about.
 *
 * - `game_id`: the card reported — exactly one of `translation_id` and `game_id` is set;
 * - `kind`: what is wrong with a card (App\Models\Report::GameKinds) — a translation report has none;
 * - `stores_answer`: what Steam and IGDB said when the report was sent, for an adult-content report
 *   (`adult` / `nothing` / `not_asked`). The stores are asked at once: when they confirm, the card
 *   is marked by them and the report closes on its own — the admin only sees what they could not
 *   settle (user, 2026-10-05: "traité automatiquement … sans embêter l'admin").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('translation_id')->nullable()->change();
            $table->foreignId('game_id')->nullable()->after('translation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->nullable()->after('game_id');
            $table->string('stores_answer', 20)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        // ⚠ Game reports are lost: they cannot be held by a table where a translation is required.
        \Illuminate\Support\Facades\DB::table('reports')->whereNull('translation_id')->delete();

        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
            $table->dropColumn(['kind', 'stores_answer']);
            $table->foreignId('translation_id')->nullable(false)->change();
        });
    }
};
