<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aggregate analytics daily at 2 AM
Schedule::command('analytics:aggregate')->dailyAt('02:00');

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
Schedule::call(function () {
    $capacity = \App\Services\LiveEditCapacity::current();
    \App\Models\AnalyticsDaily::recordCapacitySample(
        $capacity['sessions'],
        $capacity['streams']
    );
})->everyFiveMinutes()->name('sample-live-edit-capacity');
