<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What says "these accesses are on one machine", without knowing anything about the machine.
 *
 * ### The problem, measured in production on 2026-08-27
 *
 * 🔴 **Thirty-six accesses on one account, thirty-five of them named "Programme non déclaré".**
 * They had accumulated since December 2025 and nothing grouped them: the only key was
 * `device_label`, the name somebody types when linking, and nobody types it. Renaming
 * thirty-six lines by hand is not a plan, so the page listed everything and helped with nothing.
 *
 * ### 🔴 It is a random number, NOT a fingerprint
 *
 * The tempting source was ready to hand: `Secrets.MachineSecret()` already derives a stable value
 * from machine name, user name and OS. It is exactly the wrong one. Those have tiny entropy and are
 * often a real first name — a digest of them anonymises nothing, since anyone holding it can
 * CONFIRM a guess in two tries. This project already knows the trap: the visitor fingerprint
 * destroys its salt every night for that precise reason.
 *
 * So nothing is measured. The Manager draws a random value once and keeps it; the mod reads it
 * rather than inventing one, because "several games on one machine" is the Manager's remit and
 * because the mod must go on writing nothing outside its game folder.
 *
 * ### Why a slot rather than the value
 *
 * ⚠ Same construction as `game_slot`, for the same reason: the raw value is identical under every
 * account on that machine, so storing it would let anybody reading the table tie two accounts
 * together — which is what `ServerIdentity` forbids everywhere else. Salted per user, one machine
 * seen by two accounts produces two unrelated values, and neither says anything about the other.
 *
 * ⚠ Residual, stated rather than hidden: the raw value does cross the wire, exactly as the Steam id
 * does for `game_slot`. What this protects is the stored data, not the transport.
 *
 * ### Why the Manager not being there is not a hole
 *
 * No Manager, no value, no automatic grouping — and the way out is the one that now exists: each
 * program shows the code naming its own line, so a machine can be recognised and named once by
 * hand. The mechanism degrades to the manual one it replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('device_slot', 32)->nullable()->after('device_label');

            // Read on every link and on the screen, always beside the account.
            $table->index(['user_id', 'device_slot'], 'api_tokens_user_id_device_slot_index');
        });
    }

    public function down(): void
    {
        // ⚠ The index goes first and in its own statement, and the foreign key on user_id gets its
        // own back before that — a composite whose leftmost column is the key's becomes the one
        // InnoDB leans on, and refuses to be dropped (error 1553). Measured on MariaDB 10.11 the
        // same day, on the migration right before this one.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('api_tokens', function (Blueprint $table) {
                $table->index('user_id', 'api_tokens_user_id_foreign');
            });
        }

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex('api_tokens_user_id_device_slot_index');
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('device_slot');
        });
    }
};
