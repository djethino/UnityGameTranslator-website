<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that travel in the translation file but are not lines (font
 * overrides, image replacements, exclusions, variables, game settings) were
 * stored as passthrough only: nothing on the site could tell what a file
 * carried without opening it. This column holds a bounded summary, filled at
 * upload time, so game pages can show it without reading every file.
 *
 * Fonts keep their own column (font_config), already rendered on game pages.
 *
 * Existing rows stay null until `php artisan translations:backfill-derived`
 * runs — the pages treat null as "nothing to show", never as an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->json('settings_summary')->nullable()->after('font_config');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('settings_summary');
        });
    }
};
