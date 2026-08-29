<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * What we have published, and what is actually running — the two halves of "can I break this yet?".
 *
 * 🔴 **`client_usage_daily` could not answer it, and the reason is structural.** It filters on the
 * chosen span, so a version with no call inside it DISAPPEARS from the list — and a version that is
 * absent is indistinguishable from one that never existed. Nobody decides "I break this" on an
 * absence. Its figure is `MAX(installs)` over the span, i.e. a historical peak with no date: a
 * version dead for three weeks that once peaked at 40 sat at the top, a version alive yesterday with
 * 2 copies sat at the bottom.
 *
 * Two tables, because they answer two questions and hold facts of different grain — putting them
 * together would repeat the publication date once per loader:
 *
 *  - `releases`         what WE publish. Comes from GitHub, hourly.
 *  - `version_activity` what CALLS. Comes from the traffic, written beside every count.
 *
 * The screen is their join, and each gap is information: a release with no activity is "published,
 * never seen" (nobody updates); activity with no release is the `unrecognised` row.
 *
 * 🔴 **`version_activity` is fed by a TWIN WRITE, not by a job, and that is what makes it safe.**
 * `last_seen = MAX(last_seen, today)` is monotonic — it can only move forward — and it is written in
 * the same flow as the count, so there is no window in which the two tables can contradict each
 * other. This is not a cache that can drift; it is the same fact written where it can be read in one
 * row.
 *
 * ⚠ **The cost only works because of `..._180000`**, which dropped `requests` and stopped writing on
 * every call: `record()` now writes once per copy per day, so this makes it two upserts instead of
 * one, once a day. Reintroducing a per-request write reopens this calculation.
 *
 * ⚠ **`version_activity` also holds the buckets** `legacy` and `unrecognised`, which are not
 * versions — otherwise "before versioning", the one row that decides whether JSON compression can be
 * turned on, would have no `last_seen`. `releases` does not hold them: they have no publication
 * date. The two tables do not line up row for row, and that is correct.
 *
 * ⚠ **Nothing here is bounded by a list of loaders**, neither hard-coded nor from the catalogue. The
 * catalogue is the current state of what we INSTALL, so dropping a loader from it would hide the
 * copies still running on it — the exact moment one needs to see them. The guard is the shape of the
 * value (`ClientAgent`), against injection. Full reasoning in `analyse/version-inventory-admin.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();

            // 'mod' | 'manager'. Free text for the same reason DEVICES left the database: a value
            // refused here would be a measurement lost in silence.
            $table->string('product', 20);
            $table->string('version', 30);

            // ⚠ Null for anything imported before this was collected: the previous cache held tag
            // names and nothing else. The hourly refresh fills them in.
            $table->timestamp('published_at')->nullable();
            $table->boolean('prerelease')->default(false);

            $table->timestamps();

            $table->unique(['product', 'version']);
            $table->index('published_at');
        });

        Schema::create('version_activity', function (Blueprint $table) {
            $table->id();

            $table->string('product', 20);

            // The version as filed by ClientUsageDaily: a published number, or one of the two
            // buckets ('legacy', 'unrecognised').
            $table->string('version', 30);

            // ⚠ Stored as '' and never null, for the same reason as in client_usage_daily: SQLite
            // and MySQL both treat NULLs as distinct inside a unique index, which would hand a
            // variantless build a fresh row on every call.
            $table->string('variant', 40)->default('');

            $table->date('first_seen');
            $table->date('last_seen');

            // How many distinct days this build has called at all. Says something neither date can:
            // a version seen on 40 days out of 90 is in use, one seen on 2 is passing through.
            $table->unsignedInteger('days_active')->default(0);

            $table->timestamps();

            $table->unique(['product', 'version', 'variant']);
            $table->index('last_seen');
        });

        $this->seedFromExistingData();
    }

    /**
     * Fill both tables from what is already known, rather than starting empty.
     *
     * 🔴 **Not a convenience — leaving `releases` empty would poison the new table permanently.**
     * `KnownReleases::recognises()` returns false when it knows nothing, so every caller would be
     * filed as `unrecognised` until the next hourly refresh. In a table that forgets nothing, that
     * hour of mislabelled rows would stay for ever.
     *
     * ⚠ `client_usage_daily` is replayed rather than ignored: it holds the whole history since
     * 2026-08-20, and it is the only place first_seen can come from. Doing it here means the screen
     * is right on the day it ships instead of a fortnight later.
     */
    private function seedFromExistingData(): void
    {
        $stored = [];

        try {
            if (Storage::disk('local')->exists('releases/published.json')) {
                $stored = json_decode(Storage::disk('local')->get('releases/published.json'), true) ?: [];
            }
        } catch (\Throwable) {
            // A cache we cannot read is a cache we do not have; the hourly refresh will fill it.
            $stored = [];
        }

        $now = now();

        foreach (['mod', 'manager'] as $product) {
            foreach ((array) ($stored[$product] ?? []) as $version) {
                if (!is_string($version) || $version === '') {
                    continue;
                }

                DB::table('releases')->insertOrIgnore([
                    'product' => $product,
                    'version' => $version,
                    'published_at' => null,
                    'prerelease' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $rows = DB::table('client_usage_daily')
            ->selectRaw('product, version, variant, MIN(date) as first_seen, MAX(date) as last_seen, COUNT(DISTINCT date) as days_active')
            ->groupBy('product', 'version', 'variant')
            ->get();

        foreach ($rows as $row) {
            DB::table('version_activity')->insertOrIgnore([
                'product' => $row->product,
                'version' => (string) $row->version,
                'variant' => (string) ($row->variant ?? ''),
                'first_seen' => $row->first_seen,
                'last_seen' => $row->last_seen,
                'days_active' => (int) $row->days_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('version_activity');
        Schema::dropIfExists('releases');
    }
};
