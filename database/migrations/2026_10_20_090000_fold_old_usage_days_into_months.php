<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The months of the "Mod usage" screen once their days are folded (ModUsage::foldOldDays) — the
 * rule of analytics_games: days are kept 13 months, then summed per month, so the table grows with
 * months rather than with days.
 *
 * ⚠ `first_day` and `last_day` keep what the folded days said: the screen's tables show when a game
 * was first and last asked, and a month alone would turn both into its first of the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_monthly', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->string('product', 20)->default('mod');
            $table->string('act', 8);
            $table->unsignedBigInteger('game_id')->nullable();
            $table->string('steam_id', 32)->nullable();
            $table->string('game_name', 255)->nullable();
            $table->string('language', 64)->default('');
            $table->unsignedBigInteger('translation_id')->nullable();
            $table->string('outcome', 24)->default('');
            $table->boolean('signed_in')->default(false);
            $table->unsignedInteger('sessions');
            $table->date('first_day');
            $table->date('last_day');

            // ⚠ No unique index, as usage_daily: a month is always rewritten whole by the fold.
            $table->index(['month', 'act']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_monthly');
    }
};
