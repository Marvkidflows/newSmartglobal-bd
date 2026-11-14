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
// "Approaching the next rank" investor notices (audit R-32). Needs the queue worker; harmless while rank criteria are inactive.
\Illuminate\Support\Facades\Schedule::command('ranks:progress-notices')
    ->dailyAt('03:00')->timezone('UTC')->withoutOverlapping(60)->onOneServer();

// ── Previously defined in app/Console/Kernel.php, which Laravel 12 never loads, so these did not run ──
// Gated by config/scheduling.php (evaluated when the scheduler fires, not when it boots).
\Illuminate\Support\Facades\Schedule::command('investments:update-maturity')
    ->dailyAt('00:00')->timezone('UTC')->withoutOverlapping(120)->onOneServer()
    ->when(fn () => (bool) config('scheduling.investment_maturity'));

\Illuminate\Support\Facades\Schedule::command('gaming:sync-fixtures')
    ->everyThirtyMinutes()->withoutOverlapping(10)->onOneServer()
    ->when(fn () => (bool) config('scheduling.fixture_sync'));

// ── Registration Team (Task 2) ────────────────────────────────────────────────
// Hourly: one internal alert per newly-incomplete application + pruning of old anonymous visit rows. Never emails applicants.
\Illuminate\Support\Facades\Schedule::command('registration:maintain')
    ->hourly()->withoutOverlapping(30)->onOneServer();

// ── TASK 3: Mailing — start scheduled campaigns that are due (needs the scheduler + a queue worker) ──
\Illuminate\Support\Facades\Schedule::command('mailing:dispatch-scheduled')->everyMinute()->withoutOverlapping(5)->onOneServer();

// ── TASK 4: News & Media — publish scheduled articles (reads also promote due items lazily) ──
\Illuminate\Support\Facades\Schedule::command('news:publish-scheduled')->everyMinute()->withoutOverlapping(5)->onOneServer();

// ── TASK 5: Referral scan — hourly RE-ASSESSMENT of existing Pending rewards only (--no-backfill). Read-only toward money: it never approves, credits,
// rejects or changes a balance, writes an audit event only when eligibility/flags changed, and skips rows decided in the meantime. The one-off backfill of
// historic referrals stays a manual administrator action (`php artisan referrals:scan`).
\Illuminate\Support\Facades\Schedule::command('referrals:scan --no-backfill')->hourlyAt(17)->withoutOverlapping(30)->onOneServer();
