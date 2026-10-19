<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Erase the plays recorded as "by its author" (outcome `own`) — no longer recorded at all
 * (ModUsage::playOutcome, user 2026-10-20): an author is a public account, so the row named the day
 * a known person played a known game.
 *
 * ⚠ The session counts of the days already counted cannot be corrected: their sessions are
 * forgotten, so an author's session stays counted under "Plays a translation" on those few days
 * (from 2026-10-19). It names nobody. Today's are counted again from what is left.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['usage_seen', 'usage_daily', 'usage_monthly'] as $table) {
            DB::table($table)->where('act', 'play')->where('outcome', 'own')->delete();
        }
    }

    public function down(): void
    {
        // Erased on purpose: nothing to put back.
    }
};
