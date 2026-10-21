<?php
// LOCATION: tests/Feature/Rank/RankScheduleTest.php
// Proves the rank commands are REGISTERED with Laravel's scheduler and that the commands behave.
// It does NOT prove a production scheduler process is running — see RANK_MODULE.md §2.

namespace Tests\Feature\Rank;

use App\Jobs\ProcessRankEvaluationRunJob;
use App\Jobs\SendRankChangeNotificationJob;
use App\Models\InvestorRankChange;
use App\Models\RankChangeNotification;
use App\Models\RankEvaluationRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

class RankScheduleTest extends RankTestCase
{
    public function test_rank_commands_are_registered_in_the_scheduler_at_the_documented_times(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();

        // schedule:list pads the cron columns with extra spaces, so match on whitespace runs.
        $this->assertMatchesRegularExpression('/\b0\s+2\s+\*\s+\*\s+\*\s+php artisan ranks:evaluate --scheduled/', $out);
        $this->assertMatchesRegularExpression('/\*\/15\s+\*\s+\*\s+\*\s+\*\s+php artisan ranks:evaluate --retry-notifications/', $out);
    }

    public function test_sweep_does_nothing_when_no_criteria_are_active(): void
    {
        Queue::fake();
        $this->investor();

        $this->artisan('ranks:evaluate --scheduled')->assertSuccessful();

        $this->assertSame(0, RankEvaluationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_sweep_queues_one_chunked_run_and_skips_while_one_is_in_progress(): void
    {
        Queue::fake();
        $this->investor();
        $this->createCriterion('bronze', [$this->cond('account_age_days', '>=', 0)]);
        RankEvaluationRun::query()->update(['status' => 'completed']);   // ignore the run activation created

        $this->artisan('ranks:evaluate --scheduled')->assertSuccessful();
        $this->assertSame('scheduled', RankEvaluationRun::latest('id')->value('trigger'));
        Queue::assertPushed(ProcessRankEvaluationRunJob::class);

        $before = RankEvaluationRun::count();
        $this->artisan('ranks:evaluate --scheduled')->assertSuccessful();   // still pending → skip
        $this->assertSame($before, RankEvaluationRun::count());
    }

    public function test_retry_command_requeues_failed_alerts(): void
    {
        $inv = $this->investor(); $this->invest($inv, 900);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        app(\App\Services\Rank\RankEvaluationService::class)->evaluateUser($inv->id);
        $change = InvestorRankChange::where('investor_id', $inv->id)->sole();
        RankChangeNotification::where('investor_rank_change_id', $change->id)->update(['status' => 'failed']);

        Queue::fake();
        $this->artisan('ranks:evaluate --retry-notifications')->assertSuccessful();

        Queue::assertPushed(SendRankChangeNotificationJob::class, fn ($j) => $j->changeId === $change->id);
    }
}
