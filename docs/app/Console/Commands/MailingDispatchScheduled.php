<?php
// TASK 3 — `php artisan mailing:dispatch-scheduled` : starts every scheduled campaign whose time has come. Run every minute by the scheduler.
namespace App\Console\Commands;

use App\Services\Mailing\CampaignService;
use Illuminate\Console\Command;

class MailingDispatchScheduled extends Command
{
    protected $signature = 'mailing:dispatch-scheduled';
    protected $description = 'Start scheduled mailing campaigns that are due';

    public function handle(CampaignService $service): int
    {
        $this->info('Started ' . $service->dispatchDue() . ' campaign(s).');
        return self::SUCCESS;
    }
}
