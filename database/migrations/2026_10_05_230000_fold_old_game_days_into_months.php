<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attention each game got, per month, once its days are older than thirteen months.
 *
 * 🔴 **analytics_games grows with games × days** (user, 2026-10-05: anticipate rather than wait for
 * the volume). Kept day by day it is one row per game per day with traffic, for ever. Past thirteen
 * months a day's detail answers no question the admin screen asks — the spans it offers beyond a
 * year read in months — so the nightly job folds those days into this table and deletes them
 * (AggregateAnalytics::foldOldGameDays). Thirteen and not twelve: the same month of last year stays
 * readable day by day.
 *
 * ⚠ A row lives in ONE of the two tables, never both: a month is folded whole and its days deleted
 * in the same transaction, so AnalyticsGame::topOverPeriod can add the two without counting twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_games_monthly', function (Blueprint $table) {
            $table->id();
            // The first day of the month.
            $table->date('month');
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            // Same meaning as analytics_games, including its oddity: every event carrying the
            // game, downloads included (see AnalyticsGame).
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->unique(['month', 'game_id']);
        });
    }

    /** ⚠ Loses every folded month: their days were deleted when they were folded. */
    public function down(): void
    {
        Schema::dropIfExists('analytics_games_monthly');
    }
};
