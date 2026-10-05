<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A game card's picture chosen by shape from its own ids, a wide one beside it for its page's link
 * previews, and an admin's choice that the automatic pass leaves alone — see App\Services\GameArt
 * and analyse/images-des-jeux.md (user, 2026-10-06).
 *
 * - `banner_url`: the Steam store header — what a link to the game's page is previewed with
 *   (`og:image`), where the portrait picture the card now shows would be cropped.
 * - `image_chosen_at`: an admin applied a picture on /admin/games. From then on the automatic pass
 *   never moves it (StoreProposals::pictures).
 *
 * 🔴 **Every card becomes due once** (`stores_checked_at` cleared): the stores have a new question
 * for each of them — which picture its ids give — and "due" is exactly "a new question"
 * (StoreProposals::checkDue). ⚠ Not a re-timestamp: `stores_checked_at` says when the stores were
 * asked, nothing any reader sorts on; `updated_at` is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // 500 like a proposal's value: a store's picture address carries a hash and a query.
            $table->string('banner_url', 500)->nullable()->after('image_url');
            $table->timestamp('image_chosen_at')->nullable()->after('banner_url');
        });

        DB::table('games')->update(['stores_checked_at' => null]);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['banner_url', 'image_chosen_at']);
        });
    }
};
