<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the machine through the link, so one game stops collecting accesses.
 *
 * ### The accumulation, and why the cap never fired
 *
 * 🔴 The one-access-per-game cap needs a Steam id **and a device name somebody typed**. Nobody
 * types it. So re-linking the same game — a reinstall, a wiped config, "revoke everything" followed
 * by signing in again — created a line and left the previous one, every time. Measured in
 * production on 2026-08-27: **thirty-six accesses on one account**, most of them never used again.
 *
 * The missing key already exists since that morning: `api_tokens.device_slot`, which needs nobody
 * to type anything. It simply could not reach the cap — the cap runs at link time, and the machine
 * identifier only travelled on ordinary calls, which happen after.
 *
 * ### Why the raw value, and only here
 *
 * ⚠ Same shape as `game_id` beside it: the raw identifier cannot be turned into its per-account
 * form yet, because that form is salted with the account and nobody has signed in when the code is
 * issued. The row is deleted the moment the code is used, and expired ones are swept on every new
 * request — so an abandoned code does not keep it either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('game_name');
        });
    }

    public function down(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->dropColumn('device_id');
        });
    }
};
