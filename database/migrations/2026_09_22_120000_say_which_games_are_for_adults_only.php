<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which games are for adults only, and on whose word.
 *
 * 🔴 **The game is adult, never the translation and never this site.** What is served here is a
 * file of translated strings and a store cover; the classification describes the GAME somebody
 * translated. That distinction decides the wording everywhere ("Show adult games", never "adult
 * content") and it decides this table: the flag belongs to `games`.
 *
 * ## Three sources, and only one of them can say "no"
 *
 * | column | who fills it | can say |
 * |---|---|---|
 * | `adult_detected` | App\Services\AdultRating, from Steam then IGDB | yes / nothing found |
 * | `adult_declared_at` | somebody who published a translation of this game | yes |
 * | `adult_override` | an admin, from /admin | yes AND no |
 *
 * The verdict is a monotone OR: **a declaration can only ever ADD**. Nobody but an admin can
 * un-mark a game, so two contributors can never contradict each other — not declaring is not a
 * declaration that a game is all-ages. Measured on 2026-09-22, the alternative (a free boolean per
 * contributor) had no arbitration rule that did not need staff.
 *
 * ⚠ **`adult_detected` is nullable on purpose, and `null` is not `false`**: never looked at, versus
 * looked at and nothing found. Without the difference, every game would be re-asked of Steam at
 * every pass. `adult_checked_at` says when, so a game can be re-asked after a store page changes.
 *
 * ⚠ **`adult_declared_by` carries no foreign key, deliberately** — the same reasoning as
 * `translations.origin_user_id`: an inscription must survive the disappearance of what it
 * designates. An account deleted must not silently un-mark a game.
 *
 * ## `adult` is DERIVED, and recomputed on save
 *
 * The answer is stored rather than computed at read time because every listing filters on it, on
 * the most visited pages of the site. It is recomputed in App\Models\Game's `saving` hook — the
 * same mechanism `latin_search` already uses — so it cannot drift from the three columns above:
 * nothing writes it directly.
 *
 * ## The reader's side
 *
 * `users.show_adult_games` is the only durable record of an opt-in, and it exists only for people
 * who ask for it: an anonymous visitor's choice lives in the session and dies with the browser
 * (no cookie of ours — a persistent cookie whose NAME says "adult" is readable on a shared
 * machine, and Laravel encrypts the value, not the name). It is personal data: it must appear in
 * the profile export and go with the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // The answer every listing filters on. Derived — see the class comment.
            $table->boolean('adult')->default(false)->index('games_adult_index');

            // What the automatic pass concluded. null = never asked.
            $table->boolean('adult_detected')->nullable();

            // Which source said so: 'steam', 'steam_dlc' or 'igdb'. Shown to the reader, so it is
            // a vocabulary we choose, not free text.
            $table->string('adult_detected_source', 16)->nullable();

            // When the automatic pass last ran, whatever it found.
            $table->timestamp('adult_checked_at')->nullable();

            // Who declared it, and when. No foreign key — see the class comment.
            $table->unsignedBigInteger('adult_declared_by')->nullable();
            $table->timestamp('adult_declared_at')->nullable();

            // An admin's word, and the only one that can say "no". null = no admin decision.
            $table->boolean('adult_override')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_adult_games')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // ⚠ The index before the column: MariaDB drops a single-column index with its column,
            // MySQL 5.7 and SQLite do not agree on it, and a `down()` that only runs on one engine
            // is the defect this project has already paid for once (analyse/pieges-projet.md §5).
            $table->dropIndex('games_adult_index');

            $table->dropColumn([
                'adult',
                'adult_detected',
                'adult_detected_source',
                'adult_checked_at',
                'adult_declared_by',
                'adult_declared_at',
                'adult_override',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_adult_games');
        });
    }
};
