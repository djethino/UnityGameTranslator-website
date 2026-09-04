<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name a game carries on disk, kept beside the one it is displayed under.
 *
 * 🔴 **The key every client can read was being thrown away at the door.** A mod publishing a
 * translation sends `Application.productName`; when the game is new, the site asks IGDB or RAWG and
 * creates it under the OFFICIAL title instead (TranslationController::resolveGame). The one string
 * both the mod and the Manager can read off a folder — `<Game>_Data/app.info` — is then recorded
 * nowhere, and the only way back to that game is hoping one title contains the other.
 *
 * That hope is what `name LIKE %…%` was doing, and it is why a lookup could answer about several
 * games at once. It is also why a translation published under a title nobody's machine reports can
 * become unreachable: shared, and never usable.
 *
 * ⚠ **Nothing existing changes.** Both columns are nullable and start empty, so every lookup
 * behaves exactly as it did until an upload fills them in — which happens by itself, since every
 * mod already sends the name with its upload. No translation is touched, no display name moves, no
 * slug changes.
 *
 * ⚠ `unity_name` is NOT unique, and must not be: two unrelated games can ship the same
 * productName ("Game"), which is precisely why the company is kept beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // What Unity wrote in app.info — the mod sends it as game_name on every upload.
            $table->string('unity_name')->nullable()->after('name');

            // The other line of the same file. A title alone is weak ("Game", "Prototype"); with
            // the studio beside it, the pair identifies far more than either half.
            $table->string('unity_company')->nullable()->after('unity_name');

            $table->index('unity_name');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // ⚠ Named explicitly: dropping the column first would take the index with it on some
            // engines and leave dropIndex failing on the way back down — the class of defect this
            // project moved to MariaDB in development to stop shipping.
            $table->dropIndex(['unity_name']);
            $table->dropColumn(['unity_name', 'unity_company']);
        });
    }
};
