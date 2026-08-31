<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH product opened an edit session.
 *
 * 🔴 **The editor page could not name who was on the other end, so it named the wrong one.** A
 * session opened from the Manager — game closed, by design — showed "Game disconnected" and told
 * the user to restart the game, which is the one thing that session forbids. The client knew; the
 * site was never told, because when this feature was written the mod was the only caller and
 * nothing here depended on it. The Manager made that assumption false without anybody noticing.
 *
 * ⚠ **Nullable, and null means the game.** Two products are already published that send nothing:
 * refusing them, or filing them under "unknown", would break what shipped over a label. The mod
 * was the only writer before this column existed, so reading silence as the game is the honest
 * reading rather than a guess — the same decision the marker in the game folder already documents,
 * and the socle's ParseHolder is the single place that makes it.
 *
 * ⚠ **It decides wording, never permission.** The value is a declaration by an anonymous client;
 * the only thing that authorises anything on a session is its 64-character key. Nothing downstream
 * may branch on this except what a human reads.
 *
 * See analyse/live-edit-caller-identity.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            // Short and unindexed on purpose: it is read with the row it belongs to, one row at a
            // time, and never searched on.
            $table->string('holder', 16)->nullable()->after('game_name');
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->dropColumn('holder');
        });
    }
};
