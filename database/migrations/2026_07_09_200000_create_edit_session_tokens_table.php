<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edit_session_tokens', function (Blueprint $table) {
            $table->id();
            // Browser token: opens the edit page once, then consumed (one-time login)
            $table->string('token', 64)->unique();
            // Mod key: never shown to a browser — authenticates the mod's
            // content download and SSE stream for the whole session lifetime
            $table->string('mod_key', 64)->unique();
            // Display-only context sent by the mod (no Game/Translation relation)
            $table->string('game_name')->nullable();
            $table->string('source_language', 16)->nullable();
            $table->string('target_language', 16)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_session_tokens');
    }
};
