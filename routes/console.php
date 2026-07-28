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
// Ten minutes, not five: it must line up with whatever grid `schedule:run`
// itself runs on. A finer schedule than the cron behind it never fires at all.
Schedule::call(function () {
    $capacity = \App\Services\LiveEditCapacity::current();
    \App\Models\AnalyticsDaily::recordCapacitySample(
        $capacity['sessions'],
        $capacity['streams']
    );
})->everyTenMinutes()->name('sample-live-edit-capacity');
