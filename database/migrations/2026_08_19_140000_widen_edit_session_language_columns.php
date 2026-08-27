<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * These columns carry a language NAME, and were sized for an ISO code.
 *
 * 🔴 At `varchar(16)` the three catalogue entries whose name is longer — Traditional Chinese,
 * Simplified Chinese, Norwegian Nynorsk — could not be stored at all, so nobody playing a game in
 * those languages could open a browser editor. Raising only the request validation moved the
 * failure rather than fixing it: the refusal stopped being a readable 422 and became a 500.
 *
 * ⚠ **No explicit length now, on purpose.** The bound belongs to the request validation, which is
 * where it can be explained; a second number in the schema is a number that drifts from it, and
 * that drift is exactly what happened here. The default width is far above anything the catalogue
 * can hold, so the column stops being a constraint anyone has to remember.
 *
 * ⚠ **The test suite could not have caught this**, because it ran on SQLite at the time, which
 * ignores VARCHAR lengths entirely, while production runs MySQL, which enforces them. A length was
 * therefore one of the things a green test said nothing about — see the note in
 * EditSessionFlowTest. The suite has run on MySQL/MariaDB since 2026-08-27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->string('source_language')->nullable()->change();
            $table->string('target_language')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('edit_session_tokens', function (Blueprint $table) {
            $table->string('source_language', 16)->nullable()->change();
            $table->string('target_language', 16)->nullable()->change();
        });
    }
};
