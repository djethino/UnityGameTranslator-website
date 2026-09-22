<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the stores propose for a game card, and what an admin said about it.
 *
 * 🔴 **A title is a guess, an id is a fact.** Finding a game's Steam id by searching its title
 * works — measured on 2026-09-22, 9 of the 11 production games without one resolve by an exact
 * title — but a title can land on the wrong game, and an id attached to a card decides which card
 * every later upload of that game is filed under. So nothing found BY NAME is written directly:
 * it becomes a proposal, an admin accepts it with `Apply (N)`, or rejects it.
 *
 * ## Why rejections are stored, and not only acceptances
 *
 * Asked on 2026-09-22: once an admin has decided, the same thing must not come back at every
 * check. An accepted proposal cannot come back on its own — the field is filled, and nothing is
 * ever proposed over a value that is there. A rejected one would, since the store keeps giving
 * the same answer. So the row stays, `state = rejected`, and `unique(game_id, field, value)`
 * makes the next check find it instead of writing it again. A DIFFERENT value for the same field
 * is a new answer from the store, and is proposed.
 *
 * ⚠ `conflict_game_id`: the proposed value is already carried by another card. Applying it would
 * make two cards claim one game, which is a merge — a different act, with consequences for both
 * cards' translations. Shown to the admin, never applicable here.
 *
 * `games.stores_checked_at` is when the stores were last asked about a card, whatever they said —
 * a check is bounded per click (the store is rate-limited), so it works through the cards never
 * asked about first, then the oldest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_proposals', function (Blueprint $table) {
            $table->id();

            // A card removed takes its proposals with it.
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Which column the value would go into: 'steam_id', 'igdb_id' or 'image_url'. A
            // vocabulary we choose; the apply path refuses anything else.
            $table->string('field', 16);

            // 500 rather than 255: a store cover URL carries a hash and a cache-busting query.
            $table->string('value', 500);

            // Who answered: 'steam' or 'igdb'. And what they called the game — the admin decides
            // on that, not on an id nobody can read.
            $table->string('source', 16);
            $table->string('detail', 255)->nullable();

            // Another card already carries this value — see the class comment.
            $table->unsignedBigInteger('conflict_game_id')->nullable();

            // 'pending', 'rejected' or 'applied'.
            $table->string('state', 12)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->unique(['game_id', 'field', 'value']);
            $table->index(['state', 'game_id']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('stores_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('stores_checked_at');
        });

        Schema::dropIfExists('game_proposals');
    }
};
