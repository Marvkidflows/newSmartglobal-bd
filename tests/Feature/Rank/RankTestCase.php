<?php
// LOCATION: tests/Feature/Rank/RankTestCase.php
// Shared fixtures. NOTE: the numeric thresholds used in these tests are TEST DATA only. Management's PROPOSED
// thresholds (config/ranks_proposed.php) are exercised separately in RankProposedCriteriaTest and remain
// inactive in every real environment until Management gives final approval.

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\InvestorRank;
use App\Models\RankCurrencyPolicy;
use App\Models\User;
use App\Services\Rank\CurrencyPolicyService;
use App\Services\Rank\UsdValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class RankTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy rank tests exercise the engine's direct-upgrade behaviour. The approval workflow
        // (RANKS_REQUIRE_APPROVAL, default true) is covered by RankSpecComplianceTest, which switches it back on.
        config(['ranks.require_approval' => false]);
        // Legacy fixtures use arbitrary numbers; the mandatory-core guard has its own suite (RankCoreRequirementsTest) which turns it on.
        config(['ranks.enforce_core_requirements' => false]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        // The REAL default is "no confirmed currency policy", which blocks activating money criteria. The rank tests
        // exercise the engine, so they start with a confirmed policy; RankDecisionsTest and RankCurrencyPolicyTest
        // cover the gate itself (and use withoutCurrencyPolicy()).
        $this->confirmCurrencyPolicyForTests();
    }

    /** Test fixture only: a confirmed policy row for the CURRENT rules (no audit rows, no review queue involved). */
    protected function confirmCurrencyPolicyForTests(string $handling = 'accept_exclusions'): void
    {
        $svc = app(CurrencyPolicyService::class);
        DB::table('rank_currency_policies')->update(['status' => 'superseded']);
        RankCurrencyPolicy::create([
            'version' => ((int) DB::table('rank_currency_policies')->max('version')) + 1, 'status' => 'confirmed',
            'ranking_currency' => 'USD', 'rules' => $svc->rules(), 'rules_hash' => $svc->rulesHash(),
            'reviewed_by' => $this->admin->id, 'reviewed_by_name' => 'Test fixture', 'reviewed_by_role' => 'admin', 'reviewed_at' => now(), 'review_note' => 'Test fixture',
            'confirmed_by' => $this->admin->id, 'confirmed_by_name' => 'Test fixture', 'confirmed_by_role' => 'admin', 'confirmed_at' => now(),
            'confirmation_note' => 'Test fixture', 'unverified_handling' => $handling, 'accepted_review_count' => 0,
        ]);
    }

    /** Test fixture only: no policy has ever been confirmed (the real shipped state). */
    protected function withoutCurrencyPolicy(): void
    {
        DB::table('rank_currency_policies')->delete();
    }

    /** An active, VERIFIED investor (the rank prerequisites) unless the test overrides it. */
    protected function investor(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'investor', 'status' => 'active',
            'kyc_status' => 'approved', 'kyc_verified' => true,
        ], $attrs));
    }

    /** A completed cycle: status completed AND its term has elapsed (what the rank engine counts). */
    protected function investCompleted(User $user, float $amount): InvestmentAccount
    {
        $inv = $this->invest($user, $amount, 30, 'completed');
        DB::table('investment_accounts')->where('id', $inv->id)->update([
            'start_date' => now()->subDays(40)->toDateString(),
            'end_date'   => now()->subDays(10)->toDateString(),
        ]);
        return $inv->fresh();
    }

    /**
     * An investor whose recorded total is exactly $amount across exactly $cycles genuinely
     * completed investments (or, with 0 cycles, one still-active investment of $amount).
     */
    protected function investorWith(float $amount, int $cycles, array $attrs = []): User
    {
        $u = $this->investor($attrs);
        if ($cycles === 0) { $this->invest($u, $amount); return $u; }
        for ($i = 1; $i < $cycles; $i++) $this->investCompleted($u, 1.00);
        $this->investCompleted($u, round($amount - ($cycles - 1), 2));
        return $u;
    }

    protected function rank(string $key): InvestorRank
    {
        return InvestorRank::where('key', $key)->firstOrFail();
    }

    /**
     * Creates an investment. By default it is also VERIFIED as USD (a person has confirmed the currency), because the
     * rank engine ignores unverified amounts. Pass $verifyUsd = false to leave it unverified.
     */
    protected function invest(User $user, float $amount, int $days = 30, string $status = 'active', bool $verifyUsd = true): InvestmentAccount
    {
        $planId = DB::table('investment_plans')->insertGetId([
            'name' => 'Test plan ' . uniqid(), 'min_amount' => 1, 'duration_days' => $days,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inv = InvestmentAccount::create([
            'user_id' => $user->id, 'investment_plan_id' => $planId, 'amount' => $amount,
            'profit_percentage' => 0, 'expected_profit' => 0, 'status' => $status,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays($days)->toDateString(),
        ]);

        if ($verifyUsd && $status !== 'cancelled') $this->verifyUsd($inv);

        return $inv;
    }

    /** Record a USD valuation exactly as the Financial team would (the amount is stated to be in USD). */
    protected function verifyUsd(InvestmentAccount $inv, string $currency = 'USD', $rate = null, ?string $source = null, ?string $rateDate = null)
    {
        return app(UsdValuationService::class)->record(
            'investment', $inv->id, $currency, $rate, $source, $rateDate, 'Test fixture: verified by Financial', $this->admin, true
        );
    }

    /** Payload for POST /admin/ranks/criteria. */
    protected function payload(string $rankKey, array $conditions, array $extra = []): array
    {
        return array_merge([
            'investor_rank_id' => $this->rank($rankKey)->id,
            'name'             => ucfirst($rankKey) . ' criteria',
            'logic'            => 'all',
            'is_active'        => true,
            'conditions'       => $conditions,
        ], $extra);
    }

    protected function cond(string $field, string $op, $value, int $group = 1): array
    {
        return ['field' => $field, 'operator' => $op, 'value' => $value, 'group_no' => $group];
    }

    protected function createCriterion(string $rankKey, array $conditions, array $extra = []): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria', $this->payload($rankKey, $conditions, $extra));
        $res->assertSuccessful();
        return $res->json();
    }

    protected function freshRank(User $u): ?string
    {
        return $u->fresh()->investorRank?->key;
    }
}
