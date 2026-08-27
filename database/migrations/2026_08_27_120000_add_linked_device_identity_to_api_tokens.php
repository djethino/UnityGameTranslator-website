<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A token could not be told from another. `createForUser($user)` was called without a name, so
 * every row ever issued is called "Unity Mod": somebody with a dozen linked games saw a dozen
 * identical lines differing only by a date, and could neither recognise one nor spot an intruder.
 *
 * Five signals are added, and each one is here because it says something the others cannot:
 *
 *  - `public_code`  a handle to name a line out loud ("I cut #A3F2E1"). Display only — no endpoint
 *                   may ever accept it as input, or it becomes an enumeration surface.
 *  - `device_label` what the person typed when linking. The only signal that is universal and
 *                   lasting: it needs no client update, and it groups lines by machine without
 *                   any machine identifier existing anywhere.
 *  - `client_*`     which program asked, parsed from its User-Agent at link time.
 *  - `game_slot`    a per-user HMAC of the Steam id, so the same game can be recognised without
 *                   the database ever holding the game itself. Deterministic on purpose: it is
 *                   what lets one game hold one access.
 *  - `game_ref`     the game's name, encrypted (random IV), for display only.
 *
 * ⚠ `name` becomes nullable and its 'Unity Mod' default is cleared: a label that is on every row
 * is not a label. The UPDATE is raw SQL, never a save(), because Eloquent would rewrite
 * `updated_at` on every historical row — the project forbids re-stamping data.
 *
 * ⚠ `expires_at` was added nullable in 2026-02-18 with no backfill, and `findAndMarkUsed` treats
 * NULL as valid forever: the oldest and least identifiable tokens are the immortal ones. They are
 * given a year from today rather than from their creation — dating them from creation would
 * revoke them all the moment this runs, which is exactly the silent mass revocation the grace
 * period exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('public_code', 6)->nullable()->unique()->after('token');
            $table->string('device_label', 60)->nullable()->after('name');
            $table->string('client_kind', 16)->nullable()->after('device_label');
            $table->string('client_version', 16)->nullable()->after('client_kind');
            $table->string('client_variant', 32)->nullable()->after('client_version');
            $table->string('game_slot', 32)->nullable()->after('client_variant');
            $table->text('game_ref')->nullable()->after('game_slot');
            // A boolean, and deliberately no date: an unknown access that has already published
            // under the account is the one case that is urgent rather than merely untidy. A date
            // would cross with the public catalogue and pin each release on a named machine.
            $table->boolean('published_at_least_once')->default(false)->after('game_ref');
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('name')->nullable()->default(null)->change();
        });

        // One game holds one access per program, and that is looked up on every link.
        $indexName = 'api_tokens_user_id_game_slot_index';
        Schema::table('api_tokens', function (Blueprint $table) use ($indexName) {
            $table->index(['user_id', 'game_slot'], $indexName);
        });

        // The default said nothing about the row it was on. Raw, so no date moves.
        DB::table('api_tokens')->where('name', 'Unity Mod')->update(['name' => null]);

        // Immortal rows become mortal, counted from today (see the note above).
        DB::table('api_tokens')->whereNull('expires_at')->update(['expires_at' => now()->addYear()]);

        // A handle for every row that already exists. Uniqueness is enforced by the column, so a
        // collision would throw rather than silently give two lines the same name.
        $taken = [];
        foreach (DB::table('api_tokens')->whereNull('public_code')->pluck('id') as $id) {
            do {
                $code = strtoupper(Str::random(6));
            } while (isset($taken[$code]));

            $taken[$code] = true;
            DB::table('api_tokens')->where('id', $id)->update(['public_code' => $code]);
        }
    }

    public function down(): void
    {
        // ⚠ Both indexes go first, in their own statement. SQLite rebuilds the table to drop a
        // column and replays the index definitions while doing it — so an index still naming a
        // column that is on its way out makes the rebuild fail, and the rollback stops halfway.
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropUnique('api_tokens_public_code_unique');
            $table->dropIndex('api_tokens_user_id_game_slot_index');
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'public_code',
                'device_label',
                'client_kind',
                'client_version',
                'client_variant',
                'game_slot',
                'game_ref',
                'published_at_least_once',
            ]);
        });
    }
};
