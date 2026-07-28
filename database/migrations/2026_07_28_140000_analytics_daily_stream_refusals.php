<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refused SSE connections, per reason.
     *
     * The connection ceilings were raised on purpose without knowing where the
     * real limit sits (the host documents none, and the first premise about
     * entry processes turned out to be wrong). That experiment only produces an
     * answer if a refusal leaves a trace: a cap nobody can see biting looks
     * exactly like a cap that never bites.
     *
     * Two columns rather than one, because the two answers differ: refusals at
     * capacity say the global ceiling is too low, refusals per IP say a single
     * player — or a whole campus behind one NAT — is being turned away.
     *
     * High-water marks, like the peaks next to them: the SSE server counts from
     * its own start and Passenger may restart it, so the sampler keeps the
     * highest value it ever read (see AnalyticsDaily::recordCapacitySample).
     */
    public function up(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->unsignedInteger('stream_refusals_capacity')->default(0)->after('edit_sessions_refused');
            $table->unsignedInteger('stream_refusals_per_ip')->default(0)->after('stream_refusals_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->dropColumn(['stream_refusals_capacity', 'stream_refusals_per_ip']);
        });
    }
};
