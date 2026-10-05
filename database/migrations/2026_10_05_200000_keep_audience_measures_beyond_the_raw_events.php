<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the audience figures need to still be answerable in years, once the raw events are gone.
 *
 * 🔴 **The raw events live 90 days, and what is not summed before then is lost for good** (user,
 * 2026-10-05: "dans les années à venir je vais avoir besoin de connaître mon audience"). Three
 * questions had no column to survive in:
 *
 * - which pages are visited, and in which of the site's languages — `routes`, `locales`;
 * - which languages people download translations into — `download_languages`;
 * - how many distinct people in a MONTH. Summing the days counts the same person every day they
 *   came, and the daily fingerprint is forgotten the next morning by design; a month needs a
 *   fingerprint of its own (`visitor_month_hash`, a salt per month, erased once the month is
 *   counted). Same for the copies of the mod and the Manager in use (`client_monthly_seen`).
 *
 * ⚠ Nothing here points at anybody. The monthly fingerprint is an HMAC with a salt that lives in
 * the cache for the month and is never written down, exactly like the daily one; once the month is
 * counted the column is cleared and the salt expires. See analyse/retention-et-mesures.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            // The language the site was served in — never the visitor's own settings.
            $table->string('locale', 10)->nullable()->after('route');
            // The language a downloaded translation is written in; null on a page view.
            $table->string('target_language', 50)->nullable()->after('game_id');
            $table->string('visitor_month_hash', 32)->nullable()->after('visitor_hash');
        });

        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->json('routes')->nullable()->after('browsers');
            $table->json('locales')->nullable()->after('routes');
            $table->json('download_languages')->nullable()->after('locales');
        });

        Schema::create('analytics_monthly', function (Blueprint $table) {
            $table->id();
            // The first day of the month.
            $table->date('month')->unique();
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('mod_copies')->default(0);
            $table->unsignedInteger('manager_copies')->default(0);
            $table->timestamps();
        });

        // One row per copy of the mod or the Manager seen in a month, gone once the month is
        // counted — the monthly twin of client_daily_seen.
        Schema::create('client_monthly_seen', function (Blueprint $table) {
            $table->date('month');
            $table->string('product', 20);
            $table->string('fingerprint', 32);
            $table->unique(['month', 'product', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_monthly_seen');
        Schema::dropIfExists('analytics_monthly');

        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->dropColumn(['routes', 'locales', 'download_languages']);
        });

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropColumn(['locale', 'target_language', 'visitor_month_hash']);
        });
    }
};
