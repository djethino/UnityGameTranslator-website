<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN a contribution was last gone through, beside the hash saying WHICH state was.
 *
 * `reviewed_hash` answers "is this the version I read?" and nothing else, so a Main looking at a
 * list of contributions they have already been through had no idea whether that was yesterday or
 * in March. The hash cannot carry it: it changes with the file, not with the reading.
 *
 * ⚠ Left alone when the contributor pushes new work. The reading DID happen on that date, and the
 * pair then says the useful thing — "you went through this on the 12th, there has been new work
 * since" — where clearing it would lose a fact that stays true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_hash');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
