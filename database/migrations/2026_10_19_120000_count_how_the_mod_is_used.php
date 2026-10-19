<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the mod is used, from the calls it already makes — the admin "Mod usage" screen (ModUsage,
 * ModUsageReport; analysis in analyse/statistiques-usages.md).
 *
 * 🔴 **Nothing new is asked of anybody** (user, 2026-10-19: "on ne piste pas nos utilisateurs").
 * The mod already asks whether a translation exists for its game, takes one, and checks the one it
 * holds; these tables only keep, in counts, what those calls already said. No telemetry was added
 * to the mod and no Manager call is counted.
 *
 * ⚠ **A session is one copy on one day**, named by the site's daily fingerprint
 * (AnalyticsEvent::visitorFingerprints) — the salt is destroyed two days later, so a copy cannot be
 * followed from one day to the next. `usage_seen` holds the day's acts per session only until the
 * night counts them into `usage_daily`, then the rows are deleted. `usage_daily` names nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_seen', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // The daily fingerprint. Never kept past the night that counts the day.
            $table->string('session', 32);
            // look | take | play (ModUsage::ACTS)
            $table->string('act', 8);
            // What the act is about, hashed for the unique index only: a game named by a machine
            // can be longer than an index allows. The readable parts are the columns below.
            $table->string('subject', 64);

            // The game, when the site knows it — otherwise the Steam id or the name the mod sent.
            $table->unsignedBigInteger('game_id')->nullable();
            $table->string('steam_id', 32)->nullable();
            $table->string('game_name', 255)->nullable();
            // A catalogue language name, '' when none was sent or it is not one.
            $table->string('language', 64)->default('');
            $table->unsignedBigInteger('translation_id')->nullable();
            // look: found | other_languages | none · play: same | differs | own | '' (unknown) · take: ''
            $table->string('outcome', 24)->default('');
            $table->boolean('signed_in')->default(false);

            // One row per session, act and subject: the last call of the day says how it stands.
            $table->unique(['date', 'session', 'act', 'subject']);
        });

        Schema::create('usage_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // look | take | play, and `session` for what each session did at most that day.
            $table->string('act', 8);
            $table->unsignedBigInteger('game_id')->nullable();
            $table->string('steam_id', 32)->nullable();
            $table->string('game_name', 255)->nullable();
            $table->string('language', 64)->default('');
            $table->unsignedBigInteger('translation_id')->nullable();
            $table->string('outcome', 24)->default('');
            $table->boolean('signed_in')->default(false);
            $table->unsignedInteger('sessions');

            // ⚠ No unique index: a day is always rewritten whole (ModUsage::countDay deletes it
            // first), never upserted row by row.
            $table->index(['date', 'act']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily');
        Schema::dropIfExists('usage_seen');
    }
};
