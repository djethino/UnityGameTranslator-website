<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entries tagged S — lines the author marked as "do not translate" — were counted
 * nowhere. They are deliberately kept out of the composition bar and out of the
 * quality score (a score that rose by marking lines would be trivial to inflate),
 * but they are still a signal worth showing: choosing to leave a fictional language
 * or a proper noun untouched is a human decision, and the author who took the time
 * is not the same as the one who ran the AI over everything.
 *
 * Stored so game pages and the mod's download cards can state it as a plain fact,
 * next to the bar rather than inside it.
 *
 * Existing rows stay at 0 until the file is uploaded again or
 * `php artisan translations:backfill-derived` runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedInteger('skipped_count')->default(0)->after('capture_count');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('skipped_count');
        });
    }
};
