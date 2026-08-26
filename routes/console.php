<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aggregate analytics daily at 2 AM
Schedule::command('analytics:aggregate')->dailyAt('02:00');

// Forget who, keep what: the audit log's IP addresses go at twelve months.
//
// The event stays — an account uploaded a translation on this date is the memory of moderation, and
// it does not expire with the obligation to be able to identify a contributor. Its own job rather
// than a step of analytics:aggregate, so that erasing personal data is visible in this file and
// cannot be removed along with a statistics change.
Schedule::command('audit:purge-ips')->dailyAt('02:30');

// Pick up changes to the shared catalogues (languages, mod loaders, AI models).
//
// These hold facts we do not decide — a provider adds a language, a loader ships a release — so
// they are fetched rather than deployed. Daily is deliberate: they move a few times a month, and
// the copy committed in resources/catalog/ means a fetch that never happens costs a novelty, not
// a failure. The command refuses anything malformed or truncated rather than overwrite with it.
Schedule::command('catalog:refresh')->dailyAt('04:30');

// Which versions of the mod and the Manager have actually been published.
//
// It is what tells a real release from a number somebody made up: a User-Agent is written by
// whoever is calling, and without this list anyone could invent versions and both fill the usage
// table and shape what it says. Anything unrecognised is folded into a single line instead.
//
// ⚠ Hourly, not daily like the catalogues: a release published at 10 a.m. would otherwise spend
// the whole day filed as unrecognised, and those first hours are exactly the ones worth watching
// when a version starts to spread. One request an hour to a public endpoint costs nothing.
Schedule::command('releases:refresh')->hourly();

// Being delisted is computed on every query, so nothing here decides anything: the state is
// already true the moment the thirtieth day passes. What no code can do on its own is say so —
// there is no event when a date is crossed — and the banners only reach somebody who came back
// by themselves. This is the one job that exists purely to create a moment.
Schedule::command('translations:notify-delisted')->dailyAt('03:00');

// Purge expired temporary sessions and their content files. Both models also
// clean up opportunistically on creation, but that depends on traffic — the
// scheduler guarantees expired multi-MB files never linger on the disk.
Schedule::call(function () {
    \App\Models\EditSessionToken::cleanupExpired();
    \App\Models\MergePreviewToken::cleanupExpired();
})->everyFifteenMinutes()->name('cleanup-temp-sessions');

// Sample live-edit concurrency and keep the day's peak.
//
// Concurrency is the one thing that cannot be reconstructed after the fact: a
// page view leaves a row, a simultaneous connection leaves nothing once it
// closes. Sampling is what makes a history possible without writing on every
// visitor request — and a history is what tells you whether the ceiling was
// ever approached, which no live gauge can, since nobody is watching at 3am.
//
// Five minutes: fine enough that any edit session — they last tens of minutes —
// is seen several times, cheap enough to be noise. A single active editor polls
// 360 times an hour; this adds 12. Nothing finer would buy accuracy, since the
// exact figure that matters (refusals) is counted as it happens.
//
// It cannot be finer than the cron driving `schedule:run` either: a task
// scheduled more often than its cron simply never fires.
// The SSE server now reports its OWN high-water mark and its refusals, so a
// spike between two samples is no longer lost — sampling only has to read them
// often enough to survive a Passenger restart, which resets those counters.
Schedule::call(function () {
    $capacity = \App\Services\LiveEditCapacity::current();
    \App\Models\AnalyticsDaily::recordCapacitySample(
        $capacity['sessions'],
        // Prefer the server's own peak; fall back to the instant count when an
        // older SSE server does not report one
        $capacity['streams_peak'] ?? $capacity['streams'],
        $capacity['refused_at_capacity'],
        $capacity['refused_per_ip']
    );
})->everyFiveMinutes()->name('sample-live-edit-capacity');
