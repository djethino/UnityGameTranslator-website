<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            // Current sha256 of the content file — lets the browser's state
            // poll and the mod's update pushes compare without re-hashing
            // a multi-MB file on every request
            $table->string('content_hash', 64)->nullable();
            // Browser presence: stamped by the page's state heartbeat,
            // cleared/set by the pagehide beacon. The mod ends the session
            // after a grace period without presence.
            $table->timestamp('browser_last_seen_at')->nullable();
            $table->timestamp('browser_left_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'browser_last_seen_at', 'browser_left_at']);
        });
    }
};
