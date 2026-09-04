<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other ids the same game is sold under.
 *
 * 🔴 **A demo is not another game, and Steam says so itself.** Asked for on 2026-09-04: "un jeu,
 * quelque soit la provenance, si c'est le même jeu, c'est la même carte de jeu (steam, gog, epic,
 * version physique)" — and "une démo a les mêmes textes, le fichier d'une démo peut servir de base
 * à la traduction du jeu complet".
 *
 * A demo carries its OWN Steam app id, so `games.steam_id` could never match: a player on the demo
 * resolved nothing, the upload path asked Steam, and Steam answered with the demo — which created a
 * second card, with its own translations, for the same text. Measured against the store API the
 * same day: app 4428690 answers `type: "demo"` and `fullgame: {appid: 4400300}`. The link is
 * published at the source; nothing here has to guess it from a title.
 *
 * ## Additive, and that is the whole design
 *
 * `games.steam_id` stays what it is: the id a card is known by, unchanged, still the first thing
 * every resolution tries. This table holds only the ids that would otherwise resolve to NOTHING —
 * a demo's, and tomorrow another store's. So:
 *
 *  - no existing row is touched, no card moves, no slug changes;
 *  - an empty table behaves exactly like the code that shipped before it;
 *  - it fills itself as people publish, the way `unity_name` does.
 *
 * ⚠ **`unique(source, value)` is the guard, not a tidiness.** An alias says "this id IS that game",
 * so letting two cards claim one id would make a resolution depend on row order. The engine refuses
 * it instead. ⚠ Note that `games.steam_id` is a plain index and NOT unique — two cards may already
 * share one there — which is exactly why an alias must never be written for an id a card already
 * carries: see App\Models\GameIdentifier for the write rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_identifiers', function (Blueprint $table) {
            $table->id();

            // The card this id resolves to. A card removed takes its aliases with it — an alias
            // pointing at nothing could only ever resolve to nothing.
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Which store issued it: 'steam' today. Short on purpose — this is a vocabulary we
            // choose, not free text, and (source, value) is indexed.
            $table->string('source', 16);
            $table->string('value', 64);

            // Why this id is attached to this card — 'demo' when Steam's own `fullgame` said so.
            // Read by nobody; kept so an admin looking at a surprising row can tell where it came
            // from without re-deriving it.
            $table->string('reason', 32)->nullable();

            $table->timestamps();

            $table->unique(['source', 'value']);
        });
    }

    public function down(): void
    {
        // ⚠ The foreign key goes before the table on MariaDB — dropping the table alone is fine
        // here (it is the child), but stating it keeps the rollback readable and safe if another
        // table ever points at this one.
        Schema::dropIfExists('game_identifiers');
    }
};
