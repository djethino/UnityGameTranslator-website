<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portuguese becomes two locales, and the accounts that chose the old one keep their choice.
 *
 * 'pt' named no region, so the interface file drifted into half Brazilian and half European
 * wording. Both variants now exist under their own code, and an account holding 'pt' is moved to
 * 'pt-BR' — the reading every standard gives a bare 'pt' (CLDR, RFC 4647) and the one /pt/ served
 * for its whole life. Nobody's preference changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠ The column was string(5). 'pt-BR' fits in exactly five characters, with nothing to
        // spare — the next locale carrying a script subtag ('zh-Hant') would have been truncated
        // on write, storing a code that resolves to nothing and silently resetting somebody to
        // English. Widened before anything is written into it.
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 12)->nullable()->change();
        });

        // ⚠ A raw update, not a model save: touching updated_at here would restamp accounts that
        // did nothing, and that column is read as "when this person last changed something".
        DB::table('users')->where('locale', 'pt')->update(['locale' => 'pt-BR']);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('locale', ['pt-BR', 'pt-PT'])->update(['locale' => 'pt']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->change();
        });
    }
};
