<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which program asked for the link, and for which game, is known at the very first call —
 * `POST /auth/device` is made by the mod or by the Manager, each carrying its own User-Agent —
 * and was thrown away. Deducing it later from a subsequent call was the fallback; knowing it at
 * birth is exact and needs no guess.
 *
 * The device code is the bridge: the program speaks first, the person confirms in a browser up to
 * fifteen minutes later. So what the program said has to wait here in between.
 *
 * ⚠ The game identity is stored RAW here, and only here. It cannot be turned into its per-user
 * form yet: that form is salted with the account, and at this point nobody has signed in — the
 * code is not authorised until somebody types it. The row is deleted the moment it is used
 * (`validateCode`), and `DeviceCode::generate()` sweeps expired ones on every new request, so an
 * abandoned code does not keep it either.
 *
 * ⚠ `client_user_agent` is deliberately NOT stored: it is parsed on arrival and only the result is
 * kept. A raw agent string is data we would have no purpose for, and data without a purpose has no
 * legal basis to sit in a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->string('client_kind', 16)->nullable()->after('user_id');
            $table->string('client_version', 16)->nullable()->after('client_kind');
            $table->string('client_variant', 32)->nullable()->after('client_version');
            // Steam id when the game has one, so the cap can be applied; null otherwise, and then
            // there is no cap — a game recognised only by its product name cannot be told apart
            // from another carrying the same one, and cutting the wrong game silently is worse
            // than not capping at all.
            $table->string('game_id', 32)->nullable()->after('client_variant');
            $table->string('game_name', 120)->nullable()->after('game_id');
        });
    }

    public function down(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->dropColumn([
                'client_kind',
                'client_version',
                'client_variant',
                'game_id',
                'game_name',
            ]);
        });
    }
};
