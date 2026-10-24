<?php
// LOCATION: tests/Feature/Rank/RankSpecComplianceTest.php
//
// Acceptance tests for the official "SSI Investor Rank & Badge Management Module" specification (§9 items 1–10)
// plus the approval workflow, the five additional criteria, rank status, notifications and export.
// Thresholds below are typed out literally from the specification so a wrong seed cannot hide behind itself.

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\InvestorRankChange;
use App\Models\RankAdditionalCriterion;
use App\Models\RankCriterion;
use App\Models\RankReview;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\UsdValuationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RankSpecComplianceTest extends RankTestCase
{
    /** key => [min USD, min cycles] — spec §2 */
    private const CORE = [
        'bronze' => [100, 1], 'silver' => [1000, 2], 'gold' => [5000, 3],
        'platinum' => [10000, 5], 'diamond' => [20000, 8], 'elite' => [50000, 12],
    ];

    /** criterion => [bronze, silver, gold, platinum, diamond, elite] — spec §3 */
    private const ADDITIONAL = [
        'qualified_referrals'    => [1, 3, 5, 10, 20, 50],
        'active_investment_usd'  => [100, 500, 2500, 5000, 10000, 25000],
        'approved_deposits_usd'  => [100, 1000, 5000, 10000, 20000, 50000],
        'qualifying_investments' => [1, 2, 3, 5, 8, 12],
        'longest_duration_days'  => [7, 14, 30, 60, 90, 180],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ranks.require_approval' => true]);   // the REAL default
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** The six core rules from the specification, ACTIVE (test fixture only). */
    protected function activateSpecCore(): void
    {
        foreach (self::CORE as $key => [$amount, $cycles]) {
            $this->createCriterion($key, [
                $this->cond('total_invested', '>=', $amount),
                $this->cond('completed_investment_count', '>=', $cycles),
            ]);
        }
    }

    protected function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    protected function calc(User $investor, ?User $as = null): array
    {
        $res = $this->actingAs($as ?? $this->admin, 'sanctum')->getJson("/api/admin/ranks/investors/{$investor->id}/eligibility");
        $res->assertOk();
        return $res->json();
    }

    protected function rankRow(array $calc, string $key): array
    {
        return collect($calc['ranks'])->firstWhere('rank.key', $key);
    }

    protected function approvedDeposit(User $u, float $amount): Deposit
    {
        $d = Deposit::create(['user_id' => $u->id, 'amount' => $amount, 'payment_method' => 'usdt', 'status' => 'approved']);
        app(UsdValuationService::class)->record('deposit', $d->id, 'USD', null, null, null, 'Test fixture: verified by Financial', $this->admin, true);
        return $d;
    }

    protected function evaluate(User $u): void
    {
        app(RankEvaluationService::class)->evaluateUser($u->id, 'test');
    }

    protected function enableAdditional(string $key, ?string $policyChoice = null): void
    {
        $this->actingAs($this->admin, 'sanctum');
        if ($policyChoice) {
            $this->putJson("/api/admin/ranks/additional-criteria/$key/policy", ['choice' => $policyChoice, 'reason' => 'Management decision recorded'])->assertOk();
        }
        $this->postJson("/api/admin/ranks/additional-criteria/$key/enable", ['reason' => 'Management confirmed this criterion'])->assertOk();
    }

    // ── Spec §2: all six core thresholds ───────────────────────────────────────

    public function test_each_rank_core_threshold_and_cycle_requirement_is_enforced(): void
    {
        $this->activateSpecCore();

        foreach (self::CORE as $key => [$amount, $cycles]) {
            // exactly at the thresholds → qualifies for this rank
            $ok = $this->investorWith($amount, $cycles);
            $this->assertTrue($this->rankRow($this->calc($ok), $key)['qualifies'], "$key should qualify at exactly \$$amount / $cycles cycles");
            $this->assertSame($key, $this->calc($ok)['calculated_rank']['key'], "$key should be the highest qualifying rank");

            // one cycle short → does NOT qualify (spec §9.2)
            if ($cycles > 1) {
                $short = $this->investorWith($amount, $cycles - 1);
                $this->assertFalse($this->rankRow($this->calc($short), $key)['qualifies'], "$key must fail with one cycle fewer");
            } else {
                $none = $this->investorWith($amount, 0);   // active investment only, zero completed cycles
                $this->assertFalse($this->rankRow($this->calc($none), $key)['qualifies'], 'bronze must fail with no completed cycle');
            }

            // one dollar short on the amount → does NOT qualify
            $low = $this->investorWith($amount - 1, $cycles);
            $this->assertFalse($this->rankRow($this->calc($low), $key)['qualifies'], "$key must fail at \$" . ($amount - 1));
        }
    }

    public function test_highest_rank_wins_and_unmet_requirements_are_explained(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(20000, 5);                      // money for Diamond, cycles only for Platinum

        $c = $this->calc($u);
        $this->assertSame('platinum', $c['calculated_rank']['key']);
        $diamond = $this->rankRow($c, 'diamond');
        $this->assertFalse($diamond['qualifies']);
        $this->assertNotEmpty(array_filter($diamond['unmet'], fn ($t) => str_contains($t, 'Completed investment cycles')));
    }

    // ── Spec §3: five additional criteria, all six ranks, seeded DISABLED ──────

    public function test_all_five_additional_criteria_exist_with_the_exact_specification_thresholds_and_are_disabled(): void
    {
        $this->assertSame(5, RankAdditionalCriterion::count());
        $this->assertSame(30, DB::table('rank_additional_thresholds')->count());

        foreach (self::ADDITIONAL as $criterion => $values) {
            $row = RankAdditionalCriterion::where('key', $criterion)->firstOrFail();
            $this->assertFalse($row->enabled, "$criterion must NOT be silently activated");
            foreach (array_keys(self::CORE) as $i => $rankKey) {
                $stored = (float) DB::table('rank_additional_thresholds')
                    ->where('criterion_key', $criterion)->where('investor_rank_id', $this->rank($rankKey)->id)->value('value');
                $this->assertEquals($values[$i], $stored, "$criterion / $rankKey");
            }
        }
    }

    public function test_disabled_additional_criteria_do_not_affect_eligibility(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(10000, 5);                      // no deposits, no referrals

        $c = $this->calc($u);
        $this->assertSame('platinum', $c['calculated_rank']['key']);
        $row = $this->rankRow($c, 'platinum');
        $this->assertCount(5, $row['additional']);               // all five are DISPLAYED …
        $this->assertSame([false], array_values(array_unique(array_column($row['additional'], 'enabled'))));   // … but not enforced
    }

    public function test_core_met_but_an_enabled_additional_requirement_failed_does_not_qualify(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(10000, 5);

        $this->enableAdditional('approved_deposits_usd');         // Platinum needs $10,000 of approved deposits
        $c = $this->calc($u);
        $plat = $this->rankRow($c, 'platinum');

        $this->assertFalse($plat['qualifies']);                   // spec §9.3
        $this->assertTrue($plat['core']['met']);
        $this->assertNotEmpty(array_filter($plat['unmet'], fn ($t) => str_contains($t, 'Total approved deposits')));

        $this->approvedDeposit($u, 10000);                        // now the verified deposit exists
        $this->assertTrue($this->rankRow($this->calc($u), 'platinum')['qualifies']);
    }

    public function test_duration_criterion_requires_a_management_decision_then_applies_per_rank(): void
    {
        $this->activateSpecCore();
        $this->actingAs($this->admin, 'sanctum');

        // Management has not said "completed vs elapsed" → it cannot be switched on (nothing is guessed).
        $this->postJson('/api/admin/ranks/additional-criteria/longest_duration_days/enable', ['reason' => 'Trying to enable'])
            ->assertStatus(422)->assertJsonValidationErrors('criterion');

        $this->enableAdditional('longest_duration_days', 'completed');

        $u = $this->investorWith(10000, 5);                       // fixtures: every cycle ran 30 days (start −40d, end −10d)
        $c = $this->calc($u);
        $this->assertEquals(30, $c['additional_values']['longest_duration_days']['value']);
        $this->assertFalse($this->rankRow($c, 'platinum')['qualifies']);   // needs 60 days
        $this->assertTrue($this->rankRow($c, 'gold')['qualifies']);        // needs 30 days
        $this->assertSame('gold', $c['calculated_rank']['key']);
    }

    public function test_referrals_count_unique_qualified_investors_and_ignore_the_raw_counter(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/admin/ranks/additional-criteria/qualified_referrals/policy', ['choice' => 'active_account', 'reason' => 'Management decision'])->assertOk();

        $referrer = $this->investor();
        DB::table('users')->where('id', $referrer->id)->update(['referral_count' => 99]);   // a stale/manipulated counter proves nothing
        $this->investor(['referred_by' => $referrer->id]);
        $this->investor(['referred_by' => $referrer->id]);
        $this->investor(['referred_by' => $referrer->id, 'status' => 'suspended']);          // not an active account
        DB::table('users')->where('id', $referrer->id)->update(['referred_by' => $referrer->id]);   // self-referral

        $this->assertSame(2, $this->calc($referrer)['additional_values']['qualified_referrals']['value']);
    }

    // ── Spec §4/§7: invalid records never inflate eligibility ───────────────────

    public function test_cancelled_unverified_pending_and_duplicate_records_do_not_inflate_eligibility(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(1000, 2);                        // exactly Silver

        $this->invest($u, 5000, 30, 'cancelled');                 // cancelled
        $this->invest($u, 5000, 30, 'active', false);             // amount never verified in USD → counts $0
        Deposit::create(['user_id' => $u->id, 'amount' => 9000, 'payment_method' => 'usdt', 'status' => 'pending']);   // pending deposit

        $first = $this->invest($u, 400, 30, 'active');            // genuine
        $dup = DB::table('investment_accounts')->where('id', $first->id)->first();
        DB::table('investment_accounts')->insert([                 // double-submit duplicate of the same record
            'user_id' => $u->id, 'investment_plan_id' => $dup->investment_plan_id, 'amount' => 400, 'profit_percentage' => 0, 'expected_profit' => 0,
            'status' => 'active', 'start_date' => $dup->start_date, 'end_date' => $dup->end_date, 'created_at' => $dup->created_at, 'updated_at' => $dup->created_at,
        ]);

        $c = $this->calc($u);
        $this->assertEquals(1400.0, $c['core_values']['verified_total_invested_usd']);   // 1000 + 400 only
        $this->assertSame('silver', $c['calculated_rank']['key']);
        $this->assertNotSame('gold', $c['calculated_rank']['key']);
    }

    public function test_calculated_eligibility_recalculates_when_verified_records_change(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $this->assertSame('bronze', $this->calc($u)['calculated_rank']['key']);

        $this->investCompleted($u, 900);                          // now $1,000 over 2 cycles
        $this->assertSame('silver', $this->calc($u)['calculated_rank']['key']);

        DB::table('investment_accounts')->where('user_id', $u->id)->where('amount', 900)->update(['status' => 'cancelled']);
        $this->assertSame('bronze', $this->calc($u)['calculated_rank']['key']);
    }

    // ── Task §2: calculated eligibility ≠ approved assignment ──────────────────

    public function test_engine_only_raises_a_pending_review_and_awards_nothing(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $before = ['bal' => DB::table('users')->where('id', $u->id)->value('balance'), 'inv' => DB::table('investment_accounts')->count(), 'dep' => DB::table('deposits')->count()];

        $this->evaluate($u); $this->evaluate($u);                 // repeated evaluation must not duplicate

        $this->assertNull($this->freshRank($u));
        $this->assertSame(1, RankReview::pending()->where('investor_id', $u->id)->count());
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->count());
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_review_pending')->count());

        // A pending rank is never displayed as a rank (spec: badges derive from authorised status only).
        $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/rank')
            ->assertOk()->assertJsonPath('rank', null)->assertJsonPath('review_in_progress', true);

        $this->assertEquals($before, ['bal' => DB::table('users')->where('id', $u->id)->value('balance'), 'inv' => DB::table('investment_accounts')->count(), 'dep' => DB::table('deposits')->count()]);
    }

    public function test_admin_approval_awards_the_rank_records_audit_and_notifies_the_investor(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $this->evaluate($u);
        $review = RankReview::pending()->where('investor_id', $u->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/approve", ['note' => 'Verified against records'])
            ->assertOk()->assertJsonPath('review.status', 'approved');

        $this->assertSame('bronze', $this->freshRank($u));
        $change = InvestorRankChange::where('investor_id', $u->id)->sole();
        $this->assertSame('approved_upgrade', $change->change_type);
        $this->assertSame($this->admin->id, $change->actor_id);
        $this->assertNull($change->previous_rank_id);
        $this->assertSame($this->rank('bronze')->id, $change->new_rank_id);
        $this->assertStringContainsString('Verified against records', $change->reason);
        $this->assertNotNull($change->created_at);

        $n = $u->notifications()->where('data->type', 'rank_update')->get();
        $this->assertCount(1, $n);
        $this->assertSame('awarded', $n->first()->data['event']);

        // Deciding twice is refused; the badge now shows.
        $this->postJson("/api/admin/ranks/approvals/{$review->id}/approve")->assertStatus(422);
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/rank')->assertJsonPath('rank.key', 'bronze');
    }

    public function test_financial_team_may_approve_and_reject_but_not_configure(): void
    {
        $this->activateSpecCore();
        $fin = $this->staff('financial');
        $a = $this->investorWith(100, 1);  $this->evaluate($a);
        $b = $this->investorWith(100, 1);  $this->evaluate($b);
        $ra = RankReview::pending()->where('investor_id', $a->id)->firstOrFail();
        $rb = RankReview::pending()->where('investor_id', $b->id)->firstOrFail();

        $this->actingAs($fin, 'sanctum');
        $this->postJson("/api/financial/rank-approvals/{$ra->id}/approve", [])->assertOk();
        $this->postJson("/api/financial/rank-approvals/{$rb->id}/reject", [])->assertStatus(422);          // reason required
        $this->postJson("/api/financial/rank-approvals/{$rb->id}/reject", ['reason' => 'Proof of funds incomplete'])->assertOk();

        $this->assertSame('bronze', $this->freshRank($a));
        $this->assertNull($this->freshRank($b));
        $this->assertSame($fin->id, $ra->fresh()->decided_by);
        $this->assertSame('financial', $ra->fresh()->decided_by_role);

        // …and only the Admin may configure / assign / suspend / revoke.
        $this->putJson('/api/admin/ranks/additional-criteria/qualifying_investments/thresholds', ['thresholds' => ['bronze' => 2], 'reason' => 'nope nope'])->assertForbidden();
        $this->postJson('/api/admin/ranks/additional-criteria/qualifying_investments/enable', ['reason' => 'nope nope'])->assertForbidden();
        $this->postJson("/api/admin/ranks/investors/{$a->id}/suspend", ['reason' => 'nope nope'])->assertForbidden();
        $this->postJson("/api/admin/ranks/investors/{$a->id}/assign", ['rank_id' => $this->rank('gold')->id, 'reason' => 'nope nope'])->assertForbidden();
    }

    public function test_a_rejected_proposal_is_not_raised_again_under_the_same_rules(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $this->evaluate($u);
        $review = RankReview::pending()->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/reject", ['reason' => 'Awaiting source-of-funds check'])->assertOk();
        $this->evaluate($u); $this->evaluate($u);

        $this->assertSame(0, RankReview::pending()->count());
        $this->assertSame(1, RankReview::where('status', 'rejected')->count());
        $this->assertNull($this->freshRank($u));
    }

    public function test_approval_is_refused_when_the_investor_no_longer_qualifies(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $this->evaluate($u);
        $review = RankReview::pending()->firstOrFail();

        DB::table('investment_accounts')->where('user_id', $u->id)->update(['status' => 'cancelled']);   // source record invalidated

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/approve")->assertStatus(422);
        $this->assertNull($this->freshRank($u));
    }

    public function test_a_newer_higher_calculation_supersedes_the_older_pending_review(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        $this->evaluate($u);
        $this->investCompleted($u, 900);                          // now Silver-eligible
        $this->evaluate($u);

        $this->assertSame(1, RankReview::pending()->where('investor_id', $u->id)->count());
        $this->assertSame($this->rank('silver')->id, RankReview::pending()->where('investor_id', $u->id)->value('proposed_rank_id'));
        $this->assertSame(1, RankReview::where('status', 'withdrawn')->count());
    }

    // ── Rank status: suspend / revoke / reinstate ──────────────────────────────

    public function test_suspend_revoke_and_reinstate_need_a_reason_hide_the_badge_and_are_audited(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1);
        DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => $this->rank('bronze')->id]);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/admin/ranks/investors/{$u->id}/suspend", [])->assertStatus(422);                  // reason mandatory
        $this->postJson("/api/admin/ranks/investors/{$u->id}/suspend", ['reason' => 'Under compliance review'])->assertOk();
        $this->assertSame('suspended', $u->fresh()->rank_status);
        $this->assertNull($u->fresh()->rankBadge());
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/rank')->assertJsonPath('rank', null)->assertJsonPath('rank_status', 'suspended');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/reinstate", ['reason' => 'Review cleared'])->assertOk();
        $this->assertSame('bronze', $u->fresh()->rankBadge()['key']);

        $this->postJson("/api/admin/ranks/investors/{$u->id}/revoke", ['reason' => 'Fraud confirmed by Financial Team'])->assertOk();
        $this->assertSame('revoked', $u->fresh()->rank_status);

        $types = InvestorRankChange::where('investor_id', $u->id)->orderBy('sequence')->pluck('change_type')->all();
        $this->assertSame(['suspended', 'reinstated', 'revoked'], $types);
        foreach (InvestorRankChange::where('investor_id', $u->id)->get() as $row) {
            $this->assertSame($this->admin->id, $row->actor_id);
            $this->assertNotEmpty($row->reason);
            $this->assertNotNull($row->created_at);
        }
        $this->assertSame(3, $u->notifications()->where('data->type', 'rank_update')->count());

        // A revoked investor is skipped by the engine: no pending review is raised.
        $this->evaluate($u);
        $this->assertSame(0, RankReview::pending()->where('investor_id', $u->id)->count());
    }

    // ── Spec §3/§6: admin configuration ────────────────────────────────────────

    public function test_admin_can_change_thresholds_toggle_criteria_and_history_is_recorded(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson('/api/admin/ranks/additional-criteria/qualifying_investments/thresholds', ['thresholds' => ['bronze' => 2], 'reason' => 'Management revised'])->assertOk();
        $this->assertEquals(2, DB::table('rank_additional_thresholds')->where('criterion_key', 'qualifying_investments')->where('investor_rank_id', $this->rank('bronze')->id)->value('value'));

        // a higher rank may not be easier than a lower one (Bronze 2 > Silver 2 is fine; Bronze 3 > Silver 2 is not)
        $this->putJson('/api/admin/ranks/additional-criteria/qualifying_investments/thresholds', ['thresholds' => ['bronze' => 3], 'reason' => 'Bad change'])
            ->assertStatus(422)->assertJsonValidationErrors('thresholds');

        $this->postJson('/api/admin/ranks/additional-criteria/qualifying_investments/enable', [])->assertStatus(422);       // reason mandatory
        $this->postJson('/api/admin/ranks/additional-criteria/qualifying_investments/enable', ['reason' => 'Management approved'])->assertOk();
        $this->postJson('/api/admin/ranks/additional-criteria/qualifying_investments/disable', ['reason' => 'Management paused'])->assertOk();

        // disabling keeps the definition and thresholds
        $this->assertSame(6, DB::table('rank_additional_thresholds')->where('criterion_key', 'qualifying_investments')->count());
        $this->assertSame(['thresholds_changed', 'enabled', 'disabled'], DB::table('rank_additional_history')->orderBy('id')->pluck('action')->all());
        $this->assertSame($this->admin->id, (int) DB::table('rank_additional_history')->value('actor_id'));
    }

    public function test_money_criteria_cannot_be_enabled_until_the_currency_policy_is_confirmed(): void
    {
        $this->withoutCurrencyPolicy();   // the real shipped state: no confirmed policy
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/additional-criteria/approved_deposits_usd/enable', ['reason' => 'Management approved'])
            ->assertStatus(422);
        $this->assertFalse(RankAdditionalCriterion::where('key', 'approved_deposits_usd')->value('enabled'));
    }

    public function test_core_drafts_load_inactive_and_never_activate(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria/load-spec-drafts')->assertOk()
            ->assertJsonCount(6, 'created');

        $this->assertSame(6, RankCriterion::count());
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
        $gold = RankCriterion::where('investor_rank_id', $this->rank('gold')->id)->with('conditions')->firstOrFail();
        $this->assertEquals(
            ['total_invested' => 5000.0, 'completed_investment_count' => 3.0],
            $gold->conditions->mapWithKeys(fn ($c) => [$c->field => (float) $c->value])->all()
        );

        $this->postJson('/api/admin/ranks/criteria/load-spec-drafts')->assertOk()->assertJsonCount(0, 'created');   // idempotent
    }

    // ── Spec §8: security ──────────────────────────────────────────────────────

    public function test_unauthorised_users_cannot_configure_approve_award_or_export(): void
    {
        $inv = $this->investor();
        $other = $this->investor();

        foreach ([
            ['GET', '/api/admin/ranks/approvals'], ['GET', '/api/admin/ranks/additional-criteria'], ['GET', '/api/admin/ranks/export'],
            ['PUT', '/api/admin/ranks/additional-criteria/qualified_referrals/thresholds'], ['POST', '/api/admin/ranks/additional-criteria/qualified_referrals/enable'],
            ['POST', "/api/admin/ranks/investors/{$inv->id}/assign"], ['POST', "/api/admin/ranks/investors/{$inv->id}/suspend"],
            ['GET', '/api/financial/rank-approvals'], ['GET', '/api/financial/rank-export'],
        ] as [$method, $url]) {
            $this->actingAs($inv, 'sanctum')->json($method, $url, ['rank_id' => $this->rank('elite')->id, 'reason' => 'I deserve it'])->assertForbidden();
        }

        // An investor can never write their own rank through the profile endpoints.
        $this->actingAs($inv, 'sanctum')->putJson('/api/investor-investment/profile', ['investor_rank_id' => $this->rank('elite')->id, 'rank_status' => 'active']);
        $this->assertNull($inv->fresh()->investor_rank_id);
        $this->assertNull($other->fresh()->investor_rank_id);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/ranks/approvals')->assertUnauthorized();
    }

    public function test_export_is_authorised_complete_and_neutralises_formula_injection(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(100, 1, ['name' => '=HYPERLINK("http://evil.example")', 'full_name' => '=HYPERLINK("http://evil.example")']);
        $this->evaluate($u);

        foreach ([$this->admin, $this->staff('financial')] as $who) {
            $csv = $this->actingAs($who, 'sanctum')->get($who->role === 'admin' ? '/api/admin/ranks/export' : '/api/financial/rank-export')
                ->assertOk()->streamedContent();
            $this->assertStringContainsString('Calculated eligible rank', $csv);
            $this->assertStringContainsString($u->account_id, $csv);
            $this->assertStringNotContainsString('"=HYPERLINK', $csv);   // an unneutralised cell would start with the formula
            $this->assertStringNotContainsString(',=HYPERLINK', $csv);
            $this->assertStringContainsString("'=HYPERLINK", $csv);
        }

        $hist = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/ranks/export?type=history')->assertOk()->streamedContent();
        $this->assertStringContainsString('Review reference', $hist);
    }

    // ── Spec §9.10: deployment safety ──────────────────────────────────────────

    public function test_migration_is_reversible_and_leaves_investors_and_money_intact(): void
    {
        $u = $this->investorWith(500, 1);
        DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => $this->rank('bronze')->id]);
        $this->approvedDeposit($u, 250);
        $snapshot = [DB::table('users')->where('id', $u->id)->first()->balance, DB::table('investment_accounts')->count(), DB::table('deposits')->count()];

        $migration = require database_path('migrations/2026_10_24_100001_create_rank_approval_and_additional_criteria_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('rank_reviews'));
        $this->assertFalse(Schema::hasColumn('users', 'rank_status'));
        $this->assertSame($this->rank('bronze')->id, (int) DB::table('users')->where('id', $u->id)->value('investor_rank_id'));   // assignments survive rollback

        $migration->up();
        $this->assertSame(30, DB::table('rank_additional_thresholds')->count());
        $this->assertSame(0, RankAdditionalCriterion::where('enabled', true)->count());          // re-seeded DISABLED
        $this->assertSame('active', DB::table('users')->where('id', $u->id)->value('rank_status'));
        $this->assertSame($snapshot, [DB::table('users')->where('id', $u->id)->first()->balance, DB::table('investment_accounts')->count(), DB::table('deposits')->count()]);
    }

    public function test_rank_module_never_modifies_financial_records(): void
    {
        $this->activateSpecCore();
        $u = $this->investorWith(1000, 2);
        $this->approvedDeposit($u, 1000);
        $tables = ['deposits', 'withdrawals', 'investment_accounts', 'balance_adjustments'];
        $hash = fn () => collect($tables)->map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson())->all();
        $balance = fn () => DB::table('users')->where('id', $u->id)->value('balance');
        [$h, $b] = [$hash(), $balance()];

        $this->evaluate($u);
        $review = RankReview::pending()->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/approvals/{$review->id}/approve")->assertOk();
        $this->postJson("/api/admin/ranks/investors/{$u->id}/suspend", ['reason' => 'Compliance check'])->assertOk();

        $this->assertSame($h, $hash());
        $this->assertEquals($b, $balance());
    }
}
