<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Investor Rank module ──────────────────────────────────────────────────────
// Daily bounded sweep for time-based criteria + stuck-alert retry. Requires the
// scheduler process (`php artisan schedule:run` every minute, or `schedule:work`)
// AND a queue worker. Registered here (not in app/Console/Kernel.php) because
// this is a Laravel 12 app, where routes/console.php is the supported place.
\Illuminate\Support\Facades\Schedule::command('ranks:evaluate --scheduled')
    ->dailyAt('02:00')->timezone('UTC')->withoutOverlapping(60)->onOneServer();
\Illuminate\Support\Facades\Schedule::command('ranks:evaluate --retry-notifications')
    ->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();

// ── Previously defined in app/Console/Kernel.php, which Laravel 12 never loads, so these did not run ──
// Gated by config/scheduling.php (evaluated when the scheduler fires, not when it boots).
\Illuminate\Support\Facades\Schedule::command('investments:update-maturity')
    ->dailyAt('00:00')->timezone('UTC')->withoutOverlapping(120)->onOneServer()
    ->when(fn () => (bool) config('scheduling.investment_maturity'));

\Illuminate\Support\Facades\Schedule::command('gaming:sync-fixtures')
    ->everyThirtyMinutes()->withoutOverlapping(10)->onOneServer()
    ->when(fn () => (bool) config('scheduling.fixture_sync'));
