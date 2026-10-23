<?php
// LOCATION: tests/Feature/Rank/RankCurrencyPolicyTest.php
//
// The currency-policy workflow that unlocks money-based rank criteria (Bronze is the worked example).
// Covers: activation with no policy, the route to the configuration, activation after authorised confirmation,
// missing / invalid conversion records, correct totals (investments vs active vs deposits), unauthorised attempts,
// deactivate + reactivate, audit trail, eligibility recalculation, and that no financial record is ever changed.
//
// NOTE: these tests were written without being executed in the authoring environment (no PHP runtime was available).
// Run them with:  php artisan test --filter=RankCurrencyPolicyTest

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\FinancialAuditLog;
use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Models\RankCurrencyPolicy;
use App\Models\User;
use App\Services\Rank\CurrencyPolicyService;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankInvestmentLedger;
use App\Services\Rank\UsdValuationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class RankCurrencyPolicyTest extends RankTestCase
{
    protected User $financial;
    protected User $admin2;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->withoutCurrencyPolicy();                       // the real shipped state: nothing confirmed
        $this->financial = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->admin2    = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function svc(): CurrencyPolicyService { return app(CurrencyPolicyService::class); }

    private function bronzeDraft(): int
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria',
            $this->payload('bronze', [$this->cond('total_invested', '>=', 100), $this->cond('completed_investment_count', '>=', 1)], ['is_active' => false]))
            ->assertCreated()->json('criterion.id');
    }

    private function activate(int $id)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/criteria/{$id}/activate", ['reason' => 'Go live']);
    }

    /** Financial reviews, a DIFFERENT admin confirms — the normal two-step path. */
    private function confirmPolicy(string $handling = 'accept_exclusions', ?int $ack = null): void
    {
        $this->actingAs($this->financial, 'sanctum')->postJson('/api/financial/currency/policy/review', ['note' => 'Reviewed rules and queue'])->assertCreated();
        $body = ['note' => 'Management approved the USD policy', 'unverified_handling' => $handling];
        if ($ack !== null) $body['acknowledged_review_count'] = $ack;
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm', $body)->assertOk();
    }

    // ── 1. activation with no policy ────────────────────────────────────────────────

    public function test_activation_is_blocked_with_a_clear_explanation_and_a_link_when_no_policy_is_confirmed(): void
    {
        $id = $this->bronzeDraft();                                    // saving as a draft still works

        $res = $this->activate($id)->assertStatus(422)->assertJsonValidationErrors('conflicts')
            ->assertJsonPath('currency_gate.blocked', true)
            ->assertJsonPath('currency_gate.blockers.0.type', 'currency_unconfirmed')
            ->assertJsonPath('currency_gate.blockers.0.action.path', '/admin/ranks/policy');

        $msg = $res->json('currency_gate.blockers.0.message');
        $this->assertStringContainsString('Total invested amount', $msg);
        $this->assertStringContainsString('has not been confirmed', $msg);
        $this->assertStringContainsString('Financial Team reviews', $msg);
        $this->assertFalse(RankCriterion::find($id)->is_active);
    }

    // ── 2. the route to the configuration ───────────────────────────────────────────

    public function test_the_policy_screen_data_tells_each_role_what_it_may_do(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/currency-policy')->assertOk()
            ->assertJsonPath('state', 'unconfirmed')->assertJsonPath('confirmed', false)
            ->assertJsonPath('can_review', true)->assertJsonPath('can_confirm', true)
            ->assertJsonPath('ranking_currency', 'USD')->assertJsonStructure(['rules' => ['conversion', 'measures'], 'records']);

        $this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/currency/policy')->assertOk()
            ->assertJsonPath('can_review', true)->assertJsonPath('can_confirm', false);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks')->assertOk()
            ->assertJsonPath('currency_confirmed', false)->assertJsonPath('currency_policy.state', 'unconfirmed');
    }

    // ── 3. activation after the policy is authorised ────────────────────────────────

    public function test_review_then_confirm_unlocks_activation_and_is_attributed_and_audited(): void
    {
        $id = $this->bronzeDraft();

        $this->actingAs($this->financial, 'sanctum')->postJson('/api/financial/currency/policy/review', ['note' => 'Reviewed rules and queue'])->assertCreated();
        $this->assertSame('pending_confirmation', $this->svc()->state());
        $this->activate($id)->assertStatus(422)->assertJsonPath('currency_gate.blockers.0.type', 'currency_pending');   // reviewed, not yet confirmed

        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm',
            ['note' => 'Management approved the USD policy', 'unverified_handling' => 'accept_exclusions'])->assertOk();

        $this->assertSame('confirmed', $this->svc()->state());
        $this->activate($id)->assertOk();
        $this->assertTrue(RankCriterion::find($id)->is_active);

        $p = $this->svc()->current();
        $this->assertSame(1, (int) $p->version);
        $this->assertSame($this->financial->id, (int) $p->reviewed_by);
        $this->assertSame($this->admin2->id, (int) $p->confirmed_by);
        $this->assertNotNull($p->confirmed_at);
        $this->assertSame($this->svc()->rulesHash(), $p->rules_hash);

        foreach (['currency_policy.reviewed' => $this->financial->id, 'currency_policy.confirmed' => $this->admin2->id] as $action => $actor) {
            $this->assertTrue(FinancialAuditLog::where('action', $action)->where('actor_id', $actor)->where('entity_type', 'currency_policy')->exists(), "audit row missing: $action");
        }

        // the criteria history records WHICH policy version the activation relied on
        $row = RankCriteriaHistory::where('rank_criterion_id', $id)->where('action', 'activated')->latest('id')->first();
        $this->assertSame(1, (int) ($row->new['currency_policy_version'] ?? 0));
    }

    public function test_confirmation_cannot_be_given_before_a_review_or_by_the_reviewer(): void
    {
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm',
            ['note' => 'Management approved the USD policy', 'unverified_handling' => 'accept_exclusions'])->assertStatus(422)->assertJsonValidationErrors('policy');

        // an admin reviews, the SAME admin tries to confirm → refused by default
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/currency-policy/review', ['note' => 'Admin review of rules'])->assertCreated();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm',
            ['note' => 'Management approved the USD policy', 'unverified_handling' => 'accept_exclusions'])->assertStatus(422)->assertJsonValidationErrors('policy');
        $this->assertFalse($this->svc()->isConfirmed());

        // a second admin may confirm; nothing is ever auto-approved
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm',
            ['note' => 'Management approved the USD policy', 'unverified_handling' => 'accept_exclusions'])->assertOk();
        $this->assertTrue($this->svc()->isConfirmed());
    }

    public function test_the_policy_is_never_confirmed_by_migration_config_or_boot(): void
    {
        $this->assertSame(0, RankCurrencyPolicy::count());
        $this->assertFalse($this->svc()->isConfirmed());
        $this->assertSame('unconfirmed', $this->svc()->state());
    }

    // ── 4. missing / invalid conversion records ─────────────────────────────────────

    public function test_accepting_exclusions_requires_the_exact_number_of_records_still_unverified(): void
    {
        $u = $this->investor();
        $this->invest($u, 400, 30, 'active', false);                   // no verified USD value
        $this->actingAs($this->financial, 'sanctum')->postJson('/api/financial/currency/policy/review', ['note' => 'Reviewed rules and queue'])->assertCreated();

        $body = ['note' => 'Management approved the USD policy', 'unverified_handling' => 'accept_exclusions'];
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm', $body)->assertStatus(422)->assertJsonValidationErrors('acknowledged_review_count');
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm', $body + ['acknowledged_review_count' => 7])->assertStatus(422);
        $this->actingAs($this->admin2, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm', $body + ['acknowledged_review_count' => 1])->assertOk();

        $this->assertSame(1, (int) $this->svc()->current()->accepted_review_count);
    }

    public function test_require_clear_blocks_activation_and_names_the_affected_records_until_they_are_verified(): void
    {
        $u = $this->investor();
        $bad = $this->invest($u, 400, 30, 'active', false);            // currency unknown
        $this->confirmPolicy('require_clear');
        $id = $this->bronzeDraft();

        $res = $this->activate($id)->assertStatus(422)->assertJsonPath('currency_gate.blockers.0.type', 'currency_records')
            ->assertJsonPath('currency_gate.blockers.0.action.path', '/admin/ranks/currency');
        $this->assertStringContainsString('1 investment(s) with no verified USD value', $res->json('currency_gate.blockers.0.message'));
        $this->assertSame([$bad->id], $res->json('currency_gate.blockers.0.details.sample_record_ids.investments_unverified'));

        $this->verifyUsd($bad);                                        // Financial verifies it → queue is clear
        $this->activate($id)->assertOk();
    }

    public function test_records_without_a_valid_conversion_never_count_and_a_non_usd_amount_is_not_read_as_usd(): void
    {
        $this->confirmPolicy();
        $u = $this->investor();
        $this->invest($u, 1000, 30, 'active', false);                  // unverified → counts as 0

        $f = app(RankInvestmentLedger::class)->forUsers([$u->id])[$u->id];
        $this->assertEquals(0.0, $f['total_usd']);
        $this->assertCount(1, $f['unverified']);

        // a non-USD amount cannot be verified without a rate, source and date
        $ngn = $this->invest($u, 500000, 30, 'active', false);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(UsdValuationService::class)->record('investment', $ngn->id, 'NGN', null, null, null, 'Naira amount, no rate', $this->admin);
    }

    // ── 5. correct totals, measures kept separate ───────────────────────────────────

    public function test_total_active_and_deposits_are_separate_verified_usd_measures(): void
    {
        $u = $this->investor();
        $this->invest($u, 500, 30, 'active');                          // USD, verified
        $naira = $this->invest($u, 300000, 30, 'completed', false);
        $this->verifyUsd($naira, 'NGN', 0.00067, 'Test rate source', now()->subDay()->toDateString());   // 300000 × 0.00067 = 201.00
        Deposit::create(['user_id' => $u->id, 'amount' => 900, 'payment_method' => 'bank_transfer', 'status' => 'approved']);   // unverified deposit

        $ledger = app(RankInvestmentLedger::class);
        $f = $ledger->forUsers([$u->id])[$u->id];
        $this->assertEquals(701.00, $f['total_usd']);                   // 500 + 201: the naira figure was converted, not read as 300,000 USD
        $this->assertEquals(500.00, $f['active_usd']);                  // only the active investment
        $this->assertEquals(0.0, $ledger->depositsForUsers([$u->id])[$u->id]['total_usd']);   // deposits are NOT investments and their own total needs its own verification
    }

    // ── 6. unauthorised attempts ────────────────────────────────────────────────────

    public function test_only_authorised_roles_can_review_confirm_revoke_or_activate(): void
    {
        $id = $this->bronzeDraft();
        $investor = $this->investor();
        $note = ['note' => 'Some note that is long enough', 'unverified_handling' => 'require_clear', 'reason' => 'Reason that is long enough'];

        // Financial may review but may NOT confirm, withdraw, or activate criteria
        $this->actingAs($this->financial, 'sanctum')->postJson('/api/financial/currency/policy/review', $note)->assertCreated();
        $this->actingAs($this->financial, 'sanctum')->postJson('/api/admin/ranks/currency-policy/confirm', $note)->assertStatus(403);
        $this->actingAs($this->financial, 'sanctum')->postJson('/api/admin/ranks/currency-policy/revoke', $note)->assertStatus(403);
        $this->actingAs($this->financial, 'sanctum')->postJson("/api/admin/ranks/criteria/{$id}/activate")->assertStatus(403);

        // an investor can do none of it
        foreach ([['POST', '/api/financial/currency/policy/review'], ['POST', '/api/admin/ranks/currency-policy/confirm'], ['GET', '/api/financial/currency/policy']] as [$m, $url]) {
            $this->actingAs($investor, 'sanctum')->json($m, $url, $note)->assertStatus(403);
        }
        $this->assertFalse($this->svc()->isConfirmed());
        $this->assertFalse(RankCriterion::find($id)->is_active);
    }

    // ── 7. deactivate → reactivate; 8. audit + eligibility recalculation ────────────

    public function test_deactivation_and_reactivation_are_logged_and_reactivation_recalculates_eligibility(): void
    {
        $this->confirmPolicy();
        $id = $this->bronzeDraft();
        $this->activate($id)->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/criteria/{$id}/deactivate", ['reason' => 'Pause'])->assertOk();
        $this->assertFalse(RankCriterion::find($id)->is_active);

        $u = $this->investorWith(500, 1);                               // qualifies: ≥ $100 verified and 1 completed cycle
        $this->activate($id)->assertOk();                               // reactivation re-runs the same checks
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->assertSame('bronze', $this->freshRank($u));

        $actions = RankCriteriaHistory::where('rank_criterion_id', $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'activated', 'deactivated', 'activated'], $actions);
        $this->assertSame($this->admin->id, (int) RankCriteriaHistory::where('rank_criterion_id', $id)->where('action', 'deactivated')->value('actor_id'));
    }

    public function test_withdrawing_the_policy_relocks_money_criteria_without_changing_any_rank(): void
    {
        $this->confirmPolicy();
        $id = $this->bronzeDraft();
        $this->activate($id)->assertOk();
        $u = $this->investorWith(500, 1);
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $this->assertSame('bronze', $this->freshRank($u));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/currency-policy/revoke', ['reason' => 'Rates need re-checking'])->assertOk();
        $this->assertFalse($this->svc()->isConfirmed());
        $this->assertSame('revoked', $this->svc()->state());
        $this->assertSame('bronze', $this->freshRank($u));              // nobody is demoted

        $other = $this->investorWith(900, 1);
        app(RankEvaluationService::class)->evaluateUser($other->id);
        $this->assertNull($this->freshRank($other));                    // …and the engine ignores the money rule again
        $this->assertTrue(FinancialAuditLog::where('action', 'currency_policy.revoked')->exists());
    }

    public function test_changing_the_currency_rules_after_confirmation_makes_the_confirmation_stale(): void
    {
        $this->confirmPolicy();
        $id = $this->bronzeDraft();
        $this->assertTrue($this->svc()->isConfirmed());

        config(['ranks.rate_deviation_percent' => 55]);                 // a rule the policy covers changed
        $this->assertSame('stale', $this->svc()->state());
        $this->activate($id)->assertStatus(422)->assertJsonPath('currency_gate.blockers.0.type', 'currency_stale');
    }

    public function test_the_approved_policy_version_cannot_be_edited_or_deleted(): void
    {
        $this->confirmPolicy();
        $p = $this->svc()->current();

        $this->expectException(\LogicException::class);
        $p->forceFill(['rules_hash' => str_repeat('a', 64)])->save();
    }

    // ── 9. existing investor records and financial operations ───────────────────────

    public function test_the_workflow_never_changes_investments_deposits_balances_or_withdrawals(): void
    {
        $u = $this->investor(['balance' => 123.45]);
        $inv = $this->invest($u, 750, 30, 'active', false);
        $dep = Deposit::create(['user_id' => $u->id, 'amount' => 400, 'payment_method' => 'bank_transfer', 'status' => 'approved']);
        $snapshot = fn () => [
            DB::table('investment_accounts')->get()->toArray(), DB::table('deposits')->get()->toArray(), DB::table('withdrawals')->count(),
            (float) User::find($u->id)->balance, DB::table('balance_adjustments')->count(), DB::table('profit_adjustments')->count(),
        ];
        $before = $snapshot();

        $this->confirmPolicy('accept_exclusions', 2);
        $id = $this->bronzeDraft();
        $this->activate($id)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/currency-policy/revoke', ['reason' => 'Withdrawing to test safety'])->assertOk();

        $this->assertEquals($before, $snapshot());
    }
}
