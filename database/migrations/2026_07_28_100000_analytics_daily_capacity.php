<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily concurrency history for live edit sessions.
     *
     * Traffic and concurrency saturate for different reasons: bandwidth and
     * storage grow with visits, whereas an open SSE stream holds one of the
     * host's concurrent request slots for its whole life. The instant gauge on
     * the analytics page only tells the truth to whoever happens to be looking,
     * so the peaks are kept here instead — one row a day, sampled by the
     * scheduler, no write on any visitor request.
     *
     * These columns live in analytics_daily rather than a table of their own:
     * same grain (one row per date), and the admin page already loads that
     * range, so the history costs no extra query.
     */
    public function up(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            // High-water marks, sampled — a spike shorter than the sampling
            // interval can be missed (see AnalyticsDaily::recordCapacitySample)
            $table->unsignedInteger('peak_edit_sessions')->default(0)->after('registrations');
            $table->unsignedInteger('peak_edit_streams')->default(0)->after('peak_edit_sessions');
            $table->timestamp('peak_edit_sessions_at')->nullable()->after('peak_edit_streams');
            $table->timestamp('peak_edit_streams_at')->nullable()->after('peak_edit_sessions_at');

            // Counted as they happen, so exact — unlike the sampled peaks.
            // "refused" is the one that matters: it means the cap actually bit
            // and someone was turned away.
            $table->unsignedInteger('edit_sessions_started')->default(0)->after('peak_edit_streams_at');
            $table->unsignedInteger('edit_sessions_refused')->default(0)->after('edit_sessions_started');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->dropColumn([
                'peak_edit_sessions',
                'peak_edit_streams',
                'peak_edit_sessions_at',
                'peak_edit_streams_at',
                'edit_sessions_started',
                'edit_sessions_refused',
            ]);
        });
    }
};
