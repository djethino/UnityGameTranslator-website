<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a Main accepts contributions — the author's own decision, and false by default.
 *
 * 🔴 **Default false, and that is the point.** Keeping a translation open to branches is work
 * nobody agreed to by publishing: reading them, judging them, merging them. Somebody who wants a
 * team forks and opens their own fork; somebody who wants to be left alone should not have to
 * refuse anything.
 *
 * ⚠ **Existing translations are not swept into the default.** A Main who already has a branch, or
 * has merged one, has plainly accepted before — flipping them to "solo work" would close a door
 * they had held open, and their contributors would find themselves frozen for a decision their
 * Main never took. Both marks count, including the merged branch that has since disappeared:
 * merged_at survives it, and having accepted once is the fact being read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->boolean('accepts_branches')->default(false)->after('visibility');
        });

        // ⚠ One statement per rule rather than a loop over rows: this runs on a table that will
        // outgrow memory long before it outgrows the database, and a backfill that pages is a
        // backfill that can stop halfway.
        DB::table('translations')
            ->whereNull('merged_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('translations as branches')
                      ->whereColumn('branches.parent_id', 'translations.id')
                      ->where('branches.visibility', 'branch');
            })
            ->update(['accepts_branches' => false]);

        DB::table('translations')
            ->where(function ($query) {
                $query->whereNotNull('merged_at')
                      ->orWhereExists(function ($sub) {
                          $sub->select(DB::raw(1))
                              ->from('translations as branches')
                              ->whereColumn('branches.parent_id', 'translations.id')
                              ->where('branches.visibility', 'branch');
                      });
            })
            ->update(['accepts_branches' => true]);
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('accepts_branches');
        });
    }
};
