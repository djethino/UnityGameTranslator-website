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

        self::backfill();
    }

    /**
     * Who had plainly accepted before, and therefore keeps their door open.
     *
     * 🔴 **Matched on the LINEAGE (file_uuid), not on parent_id.** parent_id is "on delete set
     * null": a branch whose Main was deleted and republished, or any row that lost the link,
     * still belongs to the lineage and still IS a contribution somebody accepted. Reading only
     * parent_id would have closed those Mains and frozen their contributors over a null.
     *
     * ⚠ merged_at counts on its own: having taken a contribution in is acceptance, even when the
     * branch has since gone.
     *
     * ⚠ Separate and public so it can be tested. A migration's body normally cannot be — it runs
     * before any row exists — and this half is a rule about data, not about schema.
     */
    public static function backfill(): void
    {
        // ⚠ One statement per rule rather than a loop over rows: this runs on a table that will
        // outgrow memory long before it outgrows the database, and a backfill that pages is a
        // backfill that can stop halfway.
        $hasABranch = function ($query) {
            $query->select(DB::raw(1))
                  ->from('translations as branches')
                  ->whereColumn('branches.file_uuid', 'translations.file_uuid')
                  ->where('branches.visibility', 'branch');
        };

        // ⚠ Only a Main carries this decision. Scoped here as well as read that way everywhere
        // else — and it also stops a branch matching ITSELF as "this lineage has a branch",
        // which is what keying on file_uuid would otherwise do.
        DB::table('translations')->where('visibility', '!=', 'public')
            ->update(['accepts_branches' => false]);

        DB::table('translations')
            ->where('visibility', 'public')
            ->whereNull('merged_at')
            ->whereNotExists($hasABranch)
            ->update(['accepts_branches' => false]);

        DB::table('translations')
            ->where('visibility', 'public')
            ->where(function ($query) use ($hasABranch) {
                $query->whereNotNull('merged_at')->orWhereExists($hasABranch);
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
