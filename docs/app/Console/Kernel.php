<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // INTENTIONALLY EMPTY.
        // Laravel 12 binds Illuminate\Foundation\Console\Kernel, not this class, so schedules defined
        // here never ran. They now live in routes/console.php (gated by config/scheduling.php).
        // Defining them in both places would schedule them twice if this class is ever wired up.
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}