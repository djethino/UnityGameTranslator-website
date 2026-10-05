<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The names the stores give a game, beside the one its card is shown under (user, 2026-10-06).
 *
 * 🔴 **A card is titled by the store its first translation was picked from**: the same game is
 * 侠影录 on Steam and "Legacy of Shadows" on IGDB, and whoever searched the other name — on the
 * site, from the mod without an account, or in a search engine — found nothing. The other names are
 * read from the card's own ids (App\Services\StoreProposals, App\Services\GameFiling), searched with
 * the title (Game::scopeTitleMatches) and shown under it.
 *
 * ⚠ One name per line, in plain text — not a JSON column: a JSON encoder escapes 侠影录 as
 * `侠…`, and a LIKE on the column would never find the name typed.
 *
 * Every card becomes due once: the stores have a new question for each — its names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('other_names')->nullable()->after('name');
        });

        DB::table('games')->update(['stores_checked_at' => null]);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('other_names');
        });
    }
};
