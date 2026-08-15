<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language somebody wants their GAMES in, which is not the language they read this site in.
 *
 * 🔴 **They were one column doing two jobs.** `locale` is the site's interface — twenty languages,
 * the ones this site is translated into. What somebody wants to play in is one of the catalogue's
 * ninety, and the two are routinely different: plenty of people read English interfaces and want
 * French subtitles, and the catalogue carries languages this site will never be translated into.
 * Deriving one from the other quietly told a Tamil player that nothing existed for them.
 *
 * ⚠ Nullable, and it stays nullable: "not chosen" is a real answer that means "follow the interface
 * language", which is what the site did before this column existed. Defaulting it at creation would
 * turn a guess into a stated preference nobody made.
 *
 * ⚠ Wider than `locale` (5) on purpose: catalogue tags run to `zh-tw` and `apc`, and a column sized
 * for interface codes would truncate them into a different language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('game_language', 12)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('game_language');
        });
    }
};
