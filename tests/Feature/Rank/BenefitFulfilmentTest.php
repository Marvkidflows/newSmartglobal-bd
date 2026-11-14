<?php
// LOCATION: tests/Feature/Rank/BenefitFulfilmentTest.php — Phase D (optional patch 05). Isolated test data only.
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=BenefitFulfilmentTest

namespace Tests\Feature\Rank;

use App\Models\BenefitFulfilment;
use App\Models\RankBenefit;
use App\Models\RankBenefitAudit;
use App\Models\User;
use App\Services\Rank\BenefitFulfilmentService;
use App\Services\Rank\RankBenefitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitFulfilmentTest extends RankTestCase
{
    private BenefitFulfilmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app(RankBenefitService::class)->syncDefaults();
        $this->svc = app(BenefitFulfilmentService::class);
    }

    private function holder(string $rank): User
    {
        $u = $this->investor();
        DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => $this->rank($rank)->id]);
        return $u->fresh();
    }

    /** An operational, non-consent, non-financial benefit made ACTIVE (as an approved admin action would). */
    private function operational(string $rankKey = 'silver'): RankBenefit
    {
        $b = RankBenefit::whereHas('rank', fn ($q) => $q->where('key', $rankKey))->where('fulfilment', 'operational')->where('is_financial', false)->where('requires_consent', false)->firstOrFail();
        $b->forceFill(['status' => RankBenefit::STATUS_ACTIVE, 'is_published' => true])->save();
        return $b->fresh();
    }

    private function fails(callable $fn): void
    {
        try { $fn(); $this->fail('expected a ValidationException'); } catch (ValidationException $e) { $this->assertTrue(true); }
    }

    public function test_full_lifecycle_needs_an_assignee_and_evidence_and_is_audited(): void
    {
        $u = $this->holder('silver'); $b = $this->operational('silver');
        $f = $this->svc->request($u, $b, $this->admin, 'Investor asked for priority support');
        $this->assertSame('requested', $f->status);

        $this->fails(fn () => $this->svc->complete($f, 'TICKET-1001', $this->admin));          // not in progress yet
        $f = $this->svc->assign($f, $this->admin, $this->admin, now()->addDays(3)->toDateString());
        $f = $this->svc->start($f, $this->admin);
        $this->fails(fn () => $this->svc->complete($f, 'x', $this->admin));                      // evidence too short
        $f = $this->svc->complete($f, 'TICKET-1001 resolved by phone', $this->admin);

        $this->assertSame('completed', $f->status);
        $this->assertNotNull($f->completed_at);
        $this->assertSame('TICKET-1001 resolved by phone', $f->completion_reference);
        $actions = RankBenefitAudit::where('subject_type', 'fulfilment')->where('subject_id', $f->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['fulfilment_requested', 'fulfilment_assigned', 'fulfilment_started', 'fulfilment_completed'], $actions);
    }

    public function test_a_repeated_request_returns_the_same_open_one(): void
    {
        $u = $this->holder('silver'); $b = $this->operational('silver');
        $a = $this->svc->request($u, $b); $c = $this->svc->request($u, $b);
        $this->assertSame($a->id, $c->id);
        $this->assertSame(1, BenefitFulfilment::count());
    }

    public function test_a_benefit_that_is_not_active_or_not_entitled_cannot_be_requested(): void
    {
        $u = $this->holder('bronze'); $b = $this->operational('silver');
        $this->fails(fn () => $this->svc->request($u, $b));                                      // bronze investor, silver benefit
        $s = $this->holder('silver');
        $b->forceFill(['status' => RankBenefit::STATUS_PENDING])->save();
        $this->fails(fn () => $this->svc->request($s, $b->fresh()));                             // pending approval is not an entitlement
        $b->forceFill(['status' => RankBenefit::STATUS_SUSPENDED])->save();
        $this->fails(fn () => $this->svc->request($s, $b->fresh()));
        $this->assertSame(0, BenefitFulfilment::count());
    }

    public function test_a_higher_rank_holder_receives_a_cumulative_lower_rank_benefit(): void
    {
        $g = $this->holder('gold'); $b = $this->operational('silver');
        $this->assertSame('requested', $this->svc->request($g, $b)->status);
    }

    public function test_financial_platform_and_consent_benefits_are_refused(): void
    {
        $u = $this->holder('elite');
        foreach ([RankBenefit::where('is_financial', true)->firstOrFail(), RankBenefit::where('fulfilment', 'platform')->firstOrFail(), RankBenefit::where('requires_consent', true)->firstOrFail()] as $b) {
            $b->forceFill(['status' => RankBenefit::STATUS_ACTIVE, 'is_published' => true])->save();
            $this->fails(fn () => $this->svc->request($u, $b->fresh()));
        }
        $this->assertSame(0, BenefitFulfilment::count());
    }

    public function test_terminal_states_cannot_be_reopened_and_decline_needs_a_reason(): void
    {
        $u = $this->holder('silver'); $b = $this->operational('silver');
        $f = $this->svc->request($u, $b);
        $this->fails(fn () => $this->svc->decline($f, 'no', $this->admin));
        $f = $this->svc->decline($f, 'Outside current service capacity', $this->admin);
        $this->assertSame('declined', $f->status);
        $this->fails(fn () => $this->svc->assign($f, $this->admin, $this->admin));
        $again = $this->svc->request($u, $b);                                                    // a NEW request is allowed after a decline
        $this->assertNotSame($f->id, $again->id);
    }

    public function test_only_staff_can_be_assigned(): void
    {
        $u = $this->holder('silver'); $b = $this->operational('silver');
        $f = $this->svc->request($u, $b);
        $this->fails(fn () => $this->svc->assign($f, $this->investor(), $this->admin));
    }
}
