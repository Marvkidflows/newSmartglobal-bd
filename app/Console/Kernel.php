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
        // Update investment maturity daily at midnight
        $schedule->command('investments:update-maturity')
            ->dailyAt('00:00')
            ->timezone('UTC');
        
        // Optional: Run every minute for testing
        // $schedule->command('investments:update-maturity')->everyMinute();

        // Gaming & Prediction — automatic fixture synchronization.
        // Imports new fixtures (pending admin review) and refreshes
        // status/kickoff/score on fixtures already imported. Safe to
        // run this often: FixtureSyncService takes a cache lock so an
        // overlapping run (or a concurrent manual "Sync Now") never
        // races another one. Requires `php artisan schedule:work` (or
        // a real cron entry running `php artisan schedule:run` every
        // minute) to actually fire — see the deployment note in the
        // final report.
        $schedule->command('gaming:sync-fixtures')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
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