<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A column that says an account was erased, instead of guessing it from its name.
 *
 * Every deleted account used to be renamed to the literal `[Deleted]`, so "is this account gone?"
 * could only be asked by comparing display names — and three of them already collided in
 * production, which is what started this. Names are now `[Deleted-<random>]`, unique, so that
 * comparison is finished either way and the question needs a real answer.
 *
 * 🔴 **Deliberately NOT called `deleted_at`.** That is the column Laravel's SoftDeletes trait
 * claims: adding the trait to User later — a plausible thing for somebody to do — would silently
 * exclude every erased account from every query in the codebase, including the ones counting
 * translations that are still published.
 *
 * ⚠ Distinct from `account_deletions`, which records the same fact for a different reader: that
 * table survives a restore from before the deletion, this column does not. One tells a restored
 * database what must stay erased; this one tells a page what to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('account_deleted_at')->nullable()->after('ban_reason');
        });

        // The accounts erased before this column existed. They are recognisable by the exact name
        // the old code wrote, which nobody could have chosen: `[` and `]` are outside the charset
        // every rename and sign-up enforces.
        //
        // ⚠ banned_at is NOT the marker — a banned account is still somebody's, and must not be
        // rendered as erased.
        \DB::table('users')
            ->where('name', '[Deleted]')
            ->update(['account_deleted_at' => \DB::raw('COALESCE(banned_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_deleted_at');
        });
    }
};
