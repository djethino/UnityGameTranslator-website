<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the stored version be as long as the parser accepts.
 *
 * 🔴 **`POST /api/v1/auth/device` answered 500 on a long version, and the cause was a column too
 * narrow for its own contract.** `ClientAgent::cleanVersion` accepts up to 32 characters
 * (`9999.9999.9999.9999-aaaaaaaaaaaa`); `client_version` held 16. Anything between the two was a
 * failed insert on a public, unauthenticated endpoint — the one a mod calls to sign in.
 *
 * ⚠ **It was hidden by an accident, not by a guard.** Until the two User-Agent parsers were unified
 * (2026-08-29) this path truncated every version to major.minor, so nothing long ever reached the
 * column. Removing the truncation — right in itself, it hid the difference between 0.12.0 and
 * 0.12.1 — exposed a mismatch that had been there since the column was created.
 *
 * **Widened rather than clamped**: the regular expression is the contract for what a version IS, and
 * a store that cannot hold what the contract allows would silently file a legitimate long version
 * under "we do not know". 32 is not a round number, it is exactly what the pattern can produce.
 *
 * ⚠ Only these two columns carry a version the CALLER declares. `client_usage_daily`,
 * `version_activity` and `releases` only ever store a version we have published ourselves, or one of
 * the two buckets — bounded by `KnownReleases`, and short by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['api_tokens', 'device_codes'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('client_version', 32)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // ⚠ Anything that no longer fits becomes null — "we do not know", which is the same answer
        // the parser gives for a version it cannot read. Letting the engine truncate instead would
        // invent a version nobody ever ran.
        foreach (['api_tokens', 'device_codes'] as $table) {
            DB::table($table)
                ->whereRaw('CHAR_LENGTH(client_version) > 16')
                ->update(['client_version' => null]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('client_version', 16)->nullable()->change();
            });
        }
    }
};
