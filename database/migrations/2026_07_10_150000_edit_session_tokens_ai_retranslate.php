<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live edit session: the mod advertises whether ITS OWN AI backend is
 * configured (and which model), so the browser can offer per-line
 * retranslation. The site never stores any AI credential — the request
 * travels to the mod over SSE and the translation runs on the player's
 * machine with the player's config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->boolean('ai_available')->default(false)->after('content_hash');
            $table->string('ai_model', 100)->nullable()->after('ai_available');
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->dropColumn(['ai_available', 'ai_model']);
        });
    }
};
