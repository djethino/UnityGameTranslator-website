<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which program an act of the "Mod usage" screen came from (ModUsage::counts).
 *
 * The Manager's takes are counted too (user, 2026-10-19): a translation it writes into a game where
 * it installed the mod. They are not sessions of the mod, so the screen keeps them apart — hence the
 * column. Every row already stored came from the mod, which is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['usage_seen', 'usage_daily'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // 'mod' | 'manager' — ClientAgent's kinds.
                $table->string('product', 20)->default('mod')->after('date');
            });
        }
    }

    public function down(): void
    {
        foreach (['usage_seen', 'usage_daily'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('product');
            });
        }
    }
};
