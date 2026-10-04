<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch whose Main was moved to another game, and whose author has not yet confirmed that game
 * on their own machine.
 *
 * 🔴 **Decided by the user, 2026-10-05**: "ça doit bloquer la contribution de la branche tant que la
 * synchro de nom n'est pas faite. après il peut continuer à utiliser la traduction comme
 * utilisateur. sa branche reste associée à la main mais plus modifiable. ils sont censés travailler
 * sur le même projet." Set on every branch of a lineage when its Main changes game
 * (App\Services\LineageGame::move); cleared by the branch's next upload that names the lineage's
 * game as the one its author confirmed (`game_pick`).
 *
 * ⚠ False for every row written before: no Main had changed game through the site then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->boolean('game_switch_pending')->default(false)->after('game_pick');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('game_switch_pending');
        });
    }
};
