<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            // Game presence, mirroring browser_last_seen_at: stamped by every
            // mod-side call (keepalive, content push, content download).
            //
            // expires_at used to carry this information implicitly — only the
            // mod pushed it back. Now that the browser slides it too (an open
            // editor is a live session), the two signals are indistinguishable
            // there, and a forgotten tab would keep a session whose game died
            // alive forever. This column separates them again.
            //
            // Nullable and left NULL for rows created before this migration:
            // "unknown" must not be read as "gone" — those sessions stamp
            // themselves on the mod's next keepalive, at most ten minutes away.
            $table->timestamp('game_last_seen_at')->nullable()->after('browser_left_at');
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->dropColumn('game_last_seen_at');
        });
    }
};
