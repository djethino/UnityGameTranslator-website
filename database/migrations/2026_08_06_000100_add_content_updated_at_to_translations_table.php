<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When did the translation itself last change?
 *
 * updated_at could not answer that: increment('vote_count') and
 * increment('download_count') touch it, so a single vote made a translation
 * look freshly worked on. Pages showed either that misleading date or only
 * created_at (version history, community forks), leaving no way to tell an
 * active translation from an abandoned one.
 *
 * Filled whenever file_hash changes (see Translation::booted).
 *
 * Backfill: existing rows take updated_at. It is an approximation — it may sit
 * later than the real content change for rows that were voted on — but it is
 * the closest known upper bound, and it is never earlier than the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->timestamp('content_updated_at')->nullable()->after('updated_at');
        });

        DB::table('translations')->update([
            'content_updated_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('content_updated_at');
        });
    }
};
