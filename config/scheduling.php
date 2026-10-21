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

    // Imports/refreshes football fixtures every 30 minutes (no money involved; takes a cache lock;
    // logs a failed run if no API key is configured). Matches the original intent in Kernel.php.
    'fixture_sync' => (bool) env('SCHEDULE_FIXTURE_SYNC', true),
];
