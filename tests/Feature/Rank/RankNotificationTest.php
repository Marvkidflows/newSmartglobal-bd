<?php
// LOCATION: tests/Feature/Rank/RankNotificationTest.php

namespace Tests\Feature\Rank;

use App\Jobs\SendRankChangeNotificationJob;
use App\Models\InvestorRankChange;
use App\Models\RankChangeNotification;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankNotifier;
use Illuminate\Support\Facades\Queue;

class RankNotificationTest extends RankTestCase
{
    protected function upgradeOne(): array
    {
        $u = $this->investor(['name' => 'Ada Investor']);
        $this->invest($u, 4321.99);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        app(RankEvaluationService::class)->evaluateUser($u->id);
        return [$u, InvestorRankChange::where('investor_id', $u->id)->sole()];
    }

    public function test_upgrade_queues_the_alert_only_after_commit_with_a_trackable_delivery_row(): void
    {
        Queue::fake();
        $u = $this->investor(); $this->invest($u, 900);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        app(RankEvaluationService::class)->evaluateUser($u->id);

        $change = InvestorRankChange::where('investor_id', $u->id)->sole();
        Queue::assertPushed(SendRankChangeNotificationJob::class, fn ($j) => $j->changeId === $change->id);
        $this->assertSame('pending', RankChangeNotification::where('investor_rank_change_id', $change->id)->value('status'));
    }

    public function test_admin_receives_one_notification_with_the_required_details(): void
    {
        $second = User::factory()->create(['role' => 'admin', 'status' => 'active']);   // before the upgrade
        [$u, $change] = $this->upgradeOne();

        app(RankNotifier::class)->deliverChange($change->id);   // already delivered inline; must stay at one each

        foreach ([$this->admin, $second] as $admin) {
            $n = $admin->notifications()->where('data->type', 'rank_upgrade')->get();
            $this->assertCount(1, $n);
            $d = $n->first()->data;
            $this->assertSame('Ada Investor', $d['investor_name']);
            $this->assertSame($u->account_id, $d['account_id']);
            $this->assertSame('Unranked', $d['previous_rank']);
            $this->assertSame('Silver', $d['new_rank']);
            $this->assertTrue($d['automatic']);
            $this->assertNotEmpty($d['reason']);
            $this->assertNotEmpty($d['criteria_satisfied']);
            $this->assertNotEmpty($d['occurred_at']);
            $this->assertSame("/admin/ranks/investors/{$u->id}", $d['link']);
        }
        $this->assertSame('sent', RankChangeNotification::where('investor_rank_change_id', $change->id)->value('status'));
    }

    public function test_repeated_delivery_never_duplicates_notifications(): void
    {
        [, $change] = $this->upgradeOne();
        $notifier = app(RankNotifier::class);

        $notifier->deliverChange($change->id);
        $notifier->deliverChange($change->id);
        RankChangeNotification::where('investor_rank_change_id', $change->id)->update(['status' => 'failed']); // forced retry
        $notifier->deliverChange($change->id);

        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_upgrade')->count());
        app(RankEvaluationService::class)->evaluateUser($change->investor_id);   // repeat evaluation
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_upgrade')->count());
    }

    public function test_a_retry_only_reaches_admins_who_were_missed(): void
    {
        [, $change] = $this->upgradeOne();
        $notifier = app(RankNotifier::class);
        $notifier->deliverChange($change->id);

        $late = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        RankChangeNotification::where('investor_rank_change_id', $change->id)->update(['status' => 'failed']);
        $notifier->deliverChange($change->id);

        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_upgrade')->count());
        $this->assertSame(1, $late->notifications()->where('data->type', 'rank_upgrade')->count());
    }

    public function test_notification_contains_no_balances_or_amounts(): void
    {
        [, $change] = $this->upgradeOne();
        app(RankNotifier::class)->deliverChange($change->id);

        $json = json_encode($this->admin->notifications()->where('data->type', 'rank_upgrade')->first()->data);
        $this->assertStringNotContainsString('4321', $json);
        $this->assertStringNotContainsString('actual', $json);
        $this->assertStringNotContainsString('balance', $json);
    }

    public function test_delivery_failure_does_not_reverse_the_upgrade_and_is_visible_and_retryable(): void
    {
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        User::where('role', 'admin')->update(['status' => 'suspended']);     // nobody can be notified…
        $u = $this->investor(); $this->invest($u, 900);                       // …when the investor is upgraded
        $change = InvestorRankChange::where('investor_id', $u->id)->sole();

        $this->assertSame('silver', $this->freshRank($u));                    // upgrade stands
        $row = RankChangeNotification::where('investor_rank_change_id', $change->id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertNotEmpty($row->last_error);

        User::where('role', 'admin')->update(['status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/admin/ranks/alerts?status=failed')->assertOk()->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/admin/ranks')->assertOk()->assertJsonPath('failed_alerts', 1);

        Queue::fake();
        $this->postJson('/api/admin/ranks/alerts/retry')->assertOk();
        Queue::assertPushed(SendRankChangeNotificationJob::class);
    }

    public function test_the_job_marks_delivery_failed_when_all_retries_are_exhausted(): void
    {
        [, $change] = $this->upgradeOne();
        (new SendRankChangeNotificationJob($change->id))->failed(new \RuntimeException('mail driver down'));
        $this->assertSame('failed', RankChangeNotification::where('investor_rank_change_id', $change->id)->value('status'));
    }

    public function test_review_alert_is_sent_once_per_unchanged_shortfall(): void
    {
        $u = $this->investor(); $this->invest($u, 2000);
        $gold = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 1000)])['criterion']['id'];
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 9000)]]);

        for ($i = 0; $i < 3; $i++) app(RankEvaluationService::class)->evaluateUser($u->id);
        $review = \App\Models\RankDowngradeReview::sole();
        app(RankNotifier::class)->deliverReview($review->id);
        app(RankNotifier::class)->deliverReview($review->id);

        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_downgrade_review')->count());
        $this->assertSame('gold', $this->freshRank($u));
    }

    public function test_admin_can_read_notifications_through_the_existing_notification_endpoint(): void
    {
        [, $change] = $this->upgradeOne();
        app(RankNotifier::class)->deliverChange($change->id);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/notifications')->assertOk();
    }
}
