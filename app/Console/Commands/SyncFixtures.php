<?php
// LOCATION: app/Console/Commands/SyncFixtures.php
//
// Gaming & Prediction — scheduled automatic synchronization. Registered
// in App\Console\Kernel to run periodically; also runnable by hand
// (`php artisan gaming:sync-fixtures`) for local testing or a manual
// cron trigger outside of Laravel's own scheduler. Admin's "Sync Now"
// button calls the same FixtureSyncService directly over HTTP rather
// than shelling out to this command — both paths converge on one
// implementation, so behavior never drifts between "automatic" and
// "manual" sync.

namespace App\Console\Commands;

use App\Services\FixtureSyncService;
use Illuminate\Console\Command;

class SyncFixtures extends Command
{
    protected $signature = 'gaming:sync-fixtures';

    protected $description = 'Import new fixtures and refresh status/kickoff/score for existing ones from football-data.org';

    public function handle(FixtureSyncService $sync): int
    {
        $log = $sync->runFullSync('scheduler');

        if ($log->status === 'failed') {
            $this->error("Fixture sync failed: {$log->error}");
            return self::FAILURE;
        }

        $this->info($log->message ?? 'Sync completed.');
        if ($log->error) {
            $this->warn($log->error);
        }

        return self::SUCCESS;
    }
}
