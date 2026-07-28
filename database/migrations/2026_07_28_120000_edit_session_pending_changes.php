<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Edits saved in the browser that the game has not fetched yet.
     *
     * "Saved" and "received by the game" are two different events separated by
     * a delay that can be unbounded — the game may be closed, crashed, or
     * frozen in the background. The site only ever showed the first one, so a
     * player could be told their work was safe while it existed nowhere but in
     * this session's file.
     *
     * That distinction is what makes early collection safe: a session whose
     * every edit has reached the game is disposable, one that still holds
     * unfetched work is not. It also gives the editor something honest to
     * display — how much is waiting, rather than a bare "saved".
     */
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->unsignedInteger('pending_changes')->default(0)->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->dropColumn('pending_changes');
        });
    }
};
