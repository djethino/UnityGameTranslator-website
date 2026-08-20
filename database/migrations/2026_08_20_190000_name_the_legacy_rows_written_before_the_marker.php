<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give a name to the rows written before the markers had one.
 *
 * The first version of this collector stored the empty string for a build that did not say its
 * version — which is exactly what `legacy` means now. Those rows rendered as a blank cell on the
 * admin screen: nothing at all, which reads as a broken page rather than as a build nobody can
 * identify.
 *
 * ⚠ **Only the empty string is touched.** The same deployment window also produced rows carrying
 * version numbers that were never published (they came from testing the endpoints), and it is
 * tempting to fold them into `unrecognised` for consistency. That is deliberately NOT done here: a
 * migration runs at deploy time, when the published list may not have been fetched yet, and
 * "unrecognised" would then swallow perfectly real versions. Rewriting measurements from a
 * migration that cannot check its own premise is how a fixed number becomes a wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_usage_daily')
            ->where('version', '')
            ->update(['version' => 'legacy']);
    }

    public function down(): void
    {
        // ⚠ Only the rows this migration could have created: a `legacy` row written afterwards by
        // the collector itself is not ours to unname.
        DB::table('client_usage_daily')
            ->where('version', 'legacy')
            ->where('created_at', '<', '2026-08-20 19:00:00')
            ->update(['version' => '']);
    }
};
