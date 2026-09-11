<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines whose placeholders no longer match the source key — a [!v*N] dropped, duplicated or
 * invented, a bracket the game wrapped around one that went missing. The game substitutes at
 * runtime, so such a line shows a level with no number, or the token itself.
 *
 * Counted, never refused (decided 2026-09-11): the two editing surfaces already refuse the edit
 * while it is being typed, and what still arrives broken comes from a mod released before that
 * gate, a file edited by hand, or a replace-all. A refusal at upload would hit exactly the people
 * whose editor cannot yet tell them which line. Shown next to the composition bar and in the
 * editor, where the lines are named.
 *
 * The rule is the shared corpus's (`corpus/rules/placeholders.json`, App\Support\Placeholders).
 *
 * Existing rows stay at 0 until the file is uploaded again or
 * `php artisan translations:backfill-derived` runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedInteger('broken_placeholder_count')->default(0)->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('broken_placeholder_count');
        });
    }
};
