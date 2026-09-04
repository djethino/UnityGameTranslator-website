<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something to type for a game whose title is not written in latin letters.
 *
 * A player looking for 龙胤立志传 has no way to reach it from a keyboard: the search box compares
 * the string they type against the title, and there is nothing in common. The publisher's own
 * folder calls it LongYinLiZhiZhuan, Steam has no english title for it and IGDB does not know the
 * game at all — measured on 2026-09-04, both asked.
 *
 * 🔴 **A search handle, not a name.** What fills this column is a mechanical romanisation, wrong
 * often enough that showing it would be worse than showing nothing: 原神 comes out "yuan shen" for
 * a game everybody calls Genshin Impact, and japanese kanji are read with chinese values. It is
 * never displayed, and it takes no part in deciding which game an upload belongs to — `steam_id`
 * and `unity_name` do that, and a generated string must never be able to attach somebody's work to
 * a game.
 *
 * ⚠ Nullable and empty at first. Only titles carrying no latin letter ever get one, so most rows
 * stay null for ever, and every search behaves exactly as before until the column is filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('latin_search')->nullable()->after('unity_company');
            $table->index('latin_search');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // ⚠ The index by name and first — dropping the column can take it along on some
            // engines and leave the rollback failing halfway.
            $table->dropIndex(['latin_search']);
            $table->dropColumn('latin_search');
        });
    }
};
