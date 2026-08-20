<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which versions of our own software are actually out there, day by day.
 *
 * 🔴 **Two decisions had no data behind them.** Whether an old release is still running in numbers
 * — which decides whether JSON compression can be switched on without cutting those installs off
 * (see `CompressJsonResponse`) — and whether a mod loader adapter is still worth maintaining. Both
 * were guesses, because until 2026-08-20 the mod called itself `UnityGameTranslator/1.0` whatever
 * build it was, and nothing recorded who called anyway.
 *
 * 🔴 **Aggregated at write time, never event by event, and that is the privacy design.** There is
 * no row here about anybody: a row is "on this day, this many calls came from this version of this
 * product". Nothing to purge, nothing to leak, nothing that can be turned back into a person — the
 * table cannot answer "who", only "how many". It also cannot grow with traffic: its size is the
 * number of versions in circulation, not the number of requests.
 *
 * ⚠ `installs` counts DISTINCT daily fingerprints, using the same salted hash the site already
 * uses for visitors (`AnalyticsEvent::generateVisitorHash`) — reset every day by construction, so
 * one installation cannot be followed from one day to the next. It answers "roughly how many
 * copies", which `requests` alone cannot: a mod that polls often would otherwise outweigh a
 * hundred quiet ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');

            // 'mod' | 'manager'. Free text for the same reason DEVICES left the database:
            // a value refused here would be a measurement lost in silence.
            $table->string('product', 20);

            // Null for builds that never said. Every mod published up to 2026-08-20 is in there.
            $table->string('version', 30)->nullable();

            // The mod loader for the mod, null for the Manager.
            $table->string('variant', 40)->nullable();

            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedInteger('installs')->default(0);
            $table->timestamps();

            // One row per version per day — what every write upserts into.
            // ⚠ Nullable columns in a unique index: SQLite and MySQL both treat NULLs as distinct,
            // so the unversioned builds would make a new row each time. They are stored as '' at
            // write time instead; see ClientUsageDaily::record.
            $table->unique(['date', 'product', 'version', 'variant']);
            $table->index('date');
        });

        // The day's distinct fingerprints, and nothing else. Exists only so `installs` can count
        // without counting the same copy twice — no product, no version, nothing joinable.
        Schema::create('client_daily_seen', function (Blueprint $table) {
            $table->date('date');
            $table->string('fingerprint', 32);

            $table->primary(['date', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_daily_seen');
        Schema::dropIfExists('client_usage_daily');
    }
};
