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
