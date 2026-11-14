<?php
// TASK 3 — expands a claimed campaign into per-recipient rows and dispatches their send jobs.
namespace App\Jobs;

use App\Services\Mailing\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartMailingCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignService $service): void
    {
        $service->expand($this->campaignId);     // idempotent: re-running cannot create duplicate recipient rows
    }
}
