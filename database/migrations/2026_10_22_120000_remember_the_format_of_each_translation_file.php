<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The format each translation file is written in — its `_engine_version`, the form of its
 * placeholders (App\Support\TranslationFormat; analyse/versions-format-traduction.md).
 *
 * 🔴 **Why the site has to know it.** A client reads up to one format; loaded anyway, a newer file
 * is read wrong and written back in the old format, damaged without a word. Every mod released
 * before this rule says nothing and reads the format 1, so the site keeps a translation beyond it
 * out of that client's lists, update checks and downloads — which needs the format on the row,
 * not in a file read at each request.
 *
 * ⚠ **1 for every existing row, and that is the truth, not a default guessed**: no client has ever
 * written anything else — the files say 1, or nothing (0, the old `[vN]` form every reader
 * migrates). Filled at every write from then on (TranslationService::parseAndValidate, merges, forks).
 *
 * `older_format_hashes` — {format: hash} — keeps, when an update raises a translation's format, the
 * hash of the last version written in the format it leaves. An older client is answered with it
 * wherever it is told the hash of what it holds (check, sync state, the lineage it runs): the
 * version it can read is the one it has, so it is told of no update it could never take
 * (decided 2026-10-22: « pas de mise à jour »). Null until a format is left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedSmallInteger('format_version')->default(1)->after('content_hash');
            $table->json('older_format_hashes')->nullable()->after('format_version');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['format_version', 'older_format_hashes']);
        });
    }
};
