<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let an analytics event name a client that is not a browser.
 *
 * 🔴 **Every translation downloaded by the mod has been failing to record since the day that code
 * was written.** `analytics_events.device` was `enum('desktop','mobile','tablet')` and the API
 * download writes `'mod'`, so the insert violated the constraint, threw, and was swallowed by the
 * try/catch around it. Verified 2026-08-20: downloading through the API produced no event at all,
 * and the reason was sitting in the log — `CHECK constraint failed: device`.
 *
 * The consequence is bigger than a missing number: the analytics screen described the people who
 * visit the website and knew nothing about the people who use the product. Every download counted
 * there came from the site's own button.
 *
 * ⚠ **A plain string, not a wider enum.** The list of what can call this site is not settled — the
 * Manager is about to appear beside the mod — and a value the database refuses is a write lost in
 * silence, which is exactly what happened. The permitted values now live in
 * `AnalyticsEvent::DEVICES`, next to the code that reads them, where adding one is a one-line
 * change that cannot fail at 3 a.m.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->string('device', 20)->default('desktop')->change();
        });
    }

    public function down(): void
    {
        // ⚠ Rows written since the change may hold values the old enum refuses ('mod', 'manager').
        // They are moved back to the default rather than losing the row, because the point of that
        // enum was never to protect data — it only ever silenced writes.
        DB::table('analytics_events')
            ->whereNotIn('device', ['desktop', 'mobile', 'tablet'])
            ->update(['device' => 'desktop']);

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->enum('device', ['desktop', 'mobile', 'tablet'])->default('desktop')->change();
        });
    }
};
