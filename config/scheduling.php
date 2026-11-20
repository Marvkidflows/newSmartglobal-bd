<?php

// Switches for the scheduled tasks defined in routes/console.php. Read at RUN time (not boot time),
// so flipping an env var + `php artisan config:clear` takes effect without a code change.

return [
    // ⚠ Pays profit into investor balances at 00:00 UTC. OFF by default. Before turning it on:
    //     1) php artisan investments:update-maturity --dry-run      (see what the first run would pay)
    //     2) have the Financial team approve that total
    //     3) set SCHEDULE_INVESTMENT_MATURITY=true and config:clear
    // The command has never actually been scheduled, so the first run settles the whole backlog.
    'investment_maturity' => (bool) env('SCHEDULE_INVESTMENT_MATURITY', false),

    // D-05: switching the flag on is NOT enough. A scheduled run pays only if BOTH of these are set (see UpdateInvestmentMaturity::scheduledRunRefusal):
    //   INVESTMENT_MATURITY_APPROVAL_REF       the Finance sign-off reference / named approver for the reviewed --dry-run total
    //   INVESTMENT_MATURITY_MAX_RUN_TOTAL      the largest total one run may pay (the approved backlog total, later the daily cap); a run above it pays nothing
    // Both default to empty, so enabling the flag by mistake still pays nobody. No value is invented here.
    'investment_maturity_approval_ref'   => env('INVESTMENT_MATURITY_APPROVAL_REF'),
    'investment_maturity_max_run_total'  => env('INVESTMENT_MATURITY_MAX_RUN_TOTAL'),

    // Imports/refreshes football fixtures every 30 minutes (no money involved; takes a cache lock;
    // logs a failed run if no API key is configured). Matches the original intent in Kernel.php.
    'fixture_sync' => (bool) env('SCHEDULE_FIXTURE_SYNC', true),
];
