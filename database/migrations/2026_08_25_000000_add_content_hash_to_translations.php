<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The content of a translation, fingerprinted without its lineage identifier.
 *
 * 🔴 **file_hash cannot answer "is this the same file as somebody else's".** It hashes the uuid
 * alongside the lines, deliberately — it exists to tell a mod whether ITS translation moved. A fork
 * takes a new uuid, so two byte-identical files hash differently the moment one is forked, which is
 * precisely the case worth catching: a copy republished under another name.
 *
 * ⚠ Nullable, and it stays nullable. Rows written before this exists have none until
 * `translations:recalculate-hashes` fills them, and the duplicate check treats an absent
 * fingerprint as "cannot tell" rather than "no duplicate" — refusing an upload over a value nobody
 * ever computed would be a refusal nobody could explain.
 *
 * ⚠ Indexed, not unique. The same content legitimately exists twice in one account's history, and
 * a database constraint would turn a judgement call — whose duplicate, in what role — into a
 * write error with no message for the person uploading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('file_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
