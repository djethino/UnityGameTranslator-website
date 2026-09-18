<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a branch stood against its Main when it was last sent.
 *
 * 🔴 **The fact the site could not say.** The game tells a contributor "The Main was updated by
 * @x" and offers Merge with Main; the Manager says it on the game's card; this site, which is
 * where somebody looks over their contributions, said nothing at all. It could not: what it
 * lacked was not a rule but a value — the Main's hash at the branch's last merge, which lives in
 * the game's file under `_source.main_hash`.
 *
 * ⚠ **No field was added to the upload.** That value already travels inside `content`, which is
 * the whole file; the upload path simply reads it now and keeps it here, beside `file_hash` and
 * `content_hash` which are read the same way.
 *
 * Null on a Main, on a branch that never merged from its Main, and on everything uploaded before
 * this — unknown is not "behind", and nothing is said on a null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->string('merged_main_hash', 64)->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('merged_main_hash');
        });
    }
};
