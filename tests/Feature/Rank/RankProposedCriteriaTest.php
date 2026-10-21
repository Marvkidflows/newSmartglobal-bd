<?php
// LOCATION: tests/Feature/Rank/RankProposedCriteriaTest.php
//
// The PROPOSED Management thresholds (NOT approved) loaded via the dev-only command, and the
// prerequisites (active + verified) that gate every rank grant.
// The expected numbers below are written out literally from Management's brief so they are
// checked independently of config/ranks_proposed.php.

namespace Tests\Feature\Rank;

use App\Models\InvestmentAccount;
use App\Models\InvestorRankChange;
use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Models\RankDowngradeReview;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankFieldRegistry;
use App\Services\Rank\RankNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RankProposedCriteriaTest extends RankTestCase
{
    /** rank => [minimum amount, minimum completed cycles] — exactly as proposed by Management. */
    private const PROPOSED = [
        'bronze'   => [100,   1],
        'silver'   => [1000,  2],
        'gold'     => [5000,  3],
        'platinum' => [10000, 5],
        'diamond'  => [20000, 8],
        'elite'    => [50000, 12],
    ];

    protected function engine(): RankEvaluationService
    {
        return app(RankEvaluationService::class);
    }

    protected function loadProposed(): void
    {
        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();
    }

    // ── The loader: development only, validated, audited, never silent ──────────────

    public function test_loader_creates_the_six_proposed_rules_as_inactive_drafts_by_default(): void
    {
        $this->artisan('ranks:load-proposed')->assertSuccessful();

        $this->assertSame(6, RankCriterion::count());
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
    }

    public function test_loader_stores_exactly_managements_thresholds_for_every_rank(): void
    {
        $this->artisan('ranks:load-proposed')->assertSuccessful();

        foreach (self::PROPOSED as $key => [$amount, $cycles]) {
            $c = RankCriterion::with('conditions')->where('investor_rank_id', $this->rank($key)->id)->sole();
            $this->assertSame('all', $c->logic, "$key logic");
            $byField = $c->conditions->keyBy('field');
            $this->assertCount(2, $c->conditions, "$key condition count");
            $this->assertSame('>=', $byField['total_invested']->operator);
            $this->assertEquals($amount, $byField['total_invested']->value, "$key amount");
            $this->assertSame('>=', $byField['completed_investment_count']->operator);
            $this->assertEquals($cycles, $byField['completed_investment_count']->value, "$key cycles");
            $this->assertStringContainsString('PROPOSED', $c->description);
        }
    }

    public function test_loader_activation_is_audited_with_the_responsible_admin_and_is_idempotent(): void
    {
        $this->loadProposed();
        $this->assertSame(6, RankCriterion::where('is_active', true)->count());

        $h = RankCriteriaHistory::orderBy('id')->get();
        $this->assertTrue($h->every(fn ($r) => $r->actor_id === $this->admin->id));
        $this->assertSame(6, $h->where('action', 'created')->count());
        $this->assertSame(6, $h->where('action', 'activated')->count());

        $this->artisan('ranks:load-proposed --activate')->assertSuccessful();       // re-run
        $this->assertSame(6, RankCriterion::count());                               // nothing duplicated
    }

    public function test_activating_the_proposed_rules_moves_an_existing_investor_straight_to_their_true_rank(): void
    {
        $inv = $this->investorWith(20000, 8);
        $this->loadProposed();
        $this->engine()->evaluateUser($inv->id);

        $this->assertSame('diamond', $this->freshRank($inv));
        $this->assertSame(1, InvestorRankChange::where('investor_id', $inv->id)->count());   // not Bronze→Silver→Gold→…
    }

    public function test_loader_refuses_to_run_in_production_and_creates_nothing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('ranks:load-proposed --activate')->assertFailed();
        $this->artisan('ranks:load-proposed')->assertFailed();

        $this->assertSame(0, RankCriterion::withTrashed()->count());
    }

    public function test_loader_refuses_in_staging_style_environments_too(): void
    {
        foreach (['staging', 'production'] as $env) {
            $this->app['env'] = $env;
            $this->artisan('ranks:load-proposed --activate')->assertFailed();
        }
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_loader_refuses_a_non_local_database_even_when_app_env_is_local(): void
    {
        // The real-world trap: APP_ENV=local on a machine whose .env points at a remote database.
        $this->assertTrue(\App\Console\Commands\LoadProposedRankCriteria::isLocalDatabase(['driver' => 'sqlite']));
        $this->assertTrue(\App\Console\Commands\LoadProposedRankCriteria::isLocalDatabase(['driver' => 'mysql', 'host' => '127.0.0.1']));
        $this->assertFalse(\App\Console\Commands\LoadProposedRankCriteria::isLocalDatabase(['driver' => 'mysql', 'host' => 'db.example-remote.net']));
        $this->assertFalse(\App\Console\Commands\LoadProposedRankCriteria::isLocalDatabase(['driver' => 'mysql']));

        $original = config('database.default');
        try {
            $this->app['env'] = 'local';                                           // environment name passes…
            config(['database.default' => 'remote_fake',
                    'database.connections.remote_fake' => ['driver' => 'mysql', 'host' => 'db.example-remote.net']]);
            $this->artisan('ranks:load-proposed --activate')->assertFailed();      // …database does not
        } finally {
            config(['database.default' => $original]);                              // restore before teardown touches the DB
        }
        $this->assertSame(0, RankCriterion::withTrashed()->count());
    }

    public function test_loader_needs_an_admin_to_record_as_author(): void
    {
        User::where('role', 'admin')->delete();
        $this->artisan('ranks:load-proposed')->assertFailed();
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_nothing_is_active_unless_someone_explicitly_loads_or_enters_criteria(): void
    {
        $inv = $this->investorWith(60000, 15);
        $this->engine()->evaluateUser($inv->id);

        $this->assertNull($this->freshRank($inv));
        $this->assertSame(0, RankCriterion::count());
    }

    // ── Every threshold and boundary ────────────────────────────────────────────────

    public function test_all_six_thresholds_and_their_boundaries(): void
    {
        $this->loadProposed();

        // [amount, completed cycles, expected rank]
        $cases = [
            // below Bronze
            [99.99, 1, null], [100, 0, null], [50, 5, null],
            // Bronze: $100+, 1+ cycle
            [100, 1, 'bronze'], [999, 2, 'bronze'], [999.99, 2, 'bronze'],
            // $999 / $1,000 boundary
            [1000, 1, 'bronze'],        // enough money, not enough cycles for Silver
            [1000, 2, 'silver'],
            // Silver band top / Gold
            [4999, 3, 'silver'], [4999.99, 3, 'silver'], [5000, 2, 'silver'], [5000, 3, 'gold'],
            // Gold band top / Platinum
            [9999.99, 5, 'gold'], [10000, 4, 'gold'], [10000, 5, 'platinum'],
            // Platinum band top / Diamond
            [19999.99, 8, 'platinum'], [20000, 7, 'platinum'], [20000, 8, 'diamond'],
            // $49,999 / $50,000 boundary
            [49999, 12, 'diamond'], [49999.99, 12, 'diamond'], [50000, 11, 'diamond'], [50000, 12, 'elite'],
            // well above Elite
            [250000, 30, 'elite'],
        ];

        foreach ($cases as [$amount, $cycles, $expected]) {
            $inv = $this->investorWith($amount, $cycles);
            $this->engine()->evaluateUser($inv->id);
            $this->assertSame($expected, $this->freshRank($inv), "amount=$amount cycles=$cycles");
        }
    }

    public function test_every_cycle_minimum_is_enforced_one_cycle_short_never_qualifies(): void
    {
        $this->loadProposed();

        // For each rank: plenty of money but exactly one cycle short of the rank's minimum.
        $expectedBelow = ['bronze' => null, 'silver' => 'bronze', 'gold' => 'silver',
                          'platinum' => 'gold', 'diamond' => 'platinum', 'elite' => 'diamond'];

        foreach (self::PROPOSED as $key => [$amount, $cycles]) {
            $inv = $this->investorWith($amount, $cycles - 1);
            $this->engine()->evaluateUser($inv->id);
            // with $cycles-1 cycles and $amount money the investor can only reach the rank BELOW
            $this->assertSame($expectedBelow[$key], $this->freshRank($inv), "$key minus one cycle");
        }
    }

    public function test_every_amount_minimum_is_enforced_one_cent_short_never_qualifies(): void
    {
        $this->loadProposed();
        $expectedBelow = ['bronze' => null, 'silver' => 'bronze', 'gold' => 'silver',
                          'platinum' => 'gold', 'diamond' => 'platinum', 'elite' => 'diamond'];

        foreach (self::PROPOSED as $key => [$amount, $cycles]) {
            $inv = $this->investorWith($amount - 0.01, $cycles);
            $this->engine()->evaluateUser($inv->id);
            $this->assertSame($expectedBelow[$key], $this->freshRank($inv), "$key minus one cent");
        }
    }

    // ── Only genuine, trusted records count ─────────────────────────────────────────

    public function test_only_genuinely_completed_cycles_and_non_cancelled_amounts_count(): void
    {
        $inv = $this->investor();
        $this->investCompleted($inv, 400);                                       // genuine cycle
        $early = $this->invest($inv, 300, 30, 'completed');                      // marked completed but term NOT elapsed
        $this->invest($inv, 300, 30, 'active');                                  // still running
        $this->invest($inv, 4000, 30, 'cancelled');                              // cancelled
        $stale = $this->invest($inv, 50, 30, 'completed');                       // completed, no end date at all
        DB::table('investment_accounts')->where('id', $stale->id)->update(['end_date' => null]);

        $v = app(RankFieldRegistry::class)->resolveMany([$inv->id], ['total_invested', 'completed_investment_count'])[$inv->id];

        $this->assertSame(1, $v['completed_investment_count']);    // only the genuine cycle
        $this->assertEquals(1050.0, $v['total_invested']);         // cancelled excluded; others recorded
        $this->assertNotNull($early);
    }

    public function test_marking_an_unmatured_investment_completed_does_not_buy_a_rank(): void
    {
        $this->loadProposed();
        $inv = $this->investor();
        // 2 investments of 600 flipped to "completed" immediately (term not elapsed) → 0 genuine cycles
        $this->invest($inv, 600, 30, 'completed');
        $this->invest($inv, 600, 30, 'completed');
        $this->engine()->evaluateUser($inv->id);

        $this->assertNull($this->freshRank($inv));
    }

    public function test_a_cycle_counts_only_once_the_scheduled_end_has_passed_not_when_an_end_date_is_shortened(): void
    {
        $this->loadProposed();
        $inv = $this->investor();
        $a = $this->invest($inv, 500, 30, 'completed');                          // completed early: term not over
        $this->engine()->evaluateUser($inv->id);
        $this->assertNull($this->freshRank($inv));

        // Shortening the recorded end date alone must NOT make it count: the plan-scheduled end (start + 30 days) is still ahead.
        DB::table('investment_accounts')->where('id', $a->id)->update(['end_date' => now()->subDay()->toDateString()]);
        $this->engine()->evaluateUser($inv->id);
        $this->assertNull($this->freshRank($inv));

        // Once the scheduled end has genuinely passed as well, it counts.
        DB::table('investment_accounts')->where('id', $a->id)->update(['start_date' => now()->subDays(40)->toDateString()]);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('bronze', $this->freshRank($inv));
    }

    // ── Prerequisites: active account + verification ────────────────────────────────

    public function test_inactive_accounts_are_never_upgraded_until_they_are_active_again(): void
    {
        $this->loadProposed();

        foreach (['suspended', 'frozen', 'deactivated'] as $status) {
            $inv = $this->investorWith(1000, 2, ['status' => $status]);
            $this->engine()->evaluateUser($inv->id);
            $this->assertNull($this->freshRank($inv), "$status must not be ranked");
            $this->assertSame(0, InvestorRankChange::where('investor_id', $inv->id)->count());

            $inv->update(['status' => 'active']);                                // legitimately changes
            $this->engine()->evaluateUser($inv->id);
            $this->assertSame('silver', $this->freshRank($inv), "$status → active then qualifies");
        }
    }

    public function test_unverified_accounts_are_never_upgraded_until_verified(): void
    {
        $this->loadProposed();

        $cases = [
            'kyc pending'                 => ['kyc_status' => 'pending',  'kyc_verified' => false],
            'kyc rejected'                => ['kyc_status' => 'rejected', 'kyc_verified' => false],
            'approved but flag not set'   => ['kyc_status' => 'approved', 'kyc_verified' => false],
        ];
        foreach ($cases as $label => $attrs) {
            $inv = $this->investorWith(5000, 3, $attrs);
            $this->engine()->evaluateUser($inv->id);
            $this->assertNull($this->freshRank($inv), $label);

            $inv->update(['kyc_status' => 'approved', 'kyc_verified' => true]);  // Admin approves
            $this->engine()->evaluateUser($inv->id);
            $this->assertSame('gold', $this->freshRank($inv), "$label → verified");
        }
    }

    public function test_prerequisites_can_be_relaxed_only_by_configuration_not_by_a_request(): void
    {
        $this->loadProposed();
        config(['ranks.require_verified_kyc' => false]);

        $inv = $this->investorWith(1000, 2, ['kyc_status' => 'pending', 'kyc_verified' => false]);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('silver', $this->freshRank($inv));
    }

    public function test_dashboard_lists_the_prerequisites_that_apply(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks')->assertOk();
        $this->assertSame(['account_active', 'kyc_approved'], array_column($res->json('prerequisites'), 'key'));
    }

    // ── No automatic downgrade; flagged for review once ─────────────────────────────

    public function test_a_ranked_investor_who_becomes_ineligible_is_flagged_not_downgraded(): void
    {
        $this->loadProposed();
        $inv = $this->investorWith(1000, 2);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('silver', $this->freshRank($inv));

        $inv->update(['status' => 'suspended']);
        for ($i = 0; $i < 3; $i++) $this->engine()->evaluateUser($inv->id);

        $this->assertSame('silver', $this->freshRank($inv));                              // untouched
        $this->assertSame(0, InvestorRankChange::where('investor_id', $inv->id)->where('change_type', 'downgrade')->count());
        $review = RankDowngradeReview::open()->where('investor_id', $inv->id)->sole();      // exactly one
        $this->assertStringContainsString('Account is active', json_encode($review->failed_conditions));

        // one alert per admin even if delivery is attempted repeatedly
        app(RankNotifier::class)->deliverReview($review->id);
        app(RankNotifier::class)->deliverReview($review->id);
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'rank_downgrade_review')->count());

        $inv->update(['status' => 'active']);                                              // eligibility returns
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame(0, RankDowngradeReview::open()->where('investor_id', $inv->id)->count());
        $this->assertSame('silver', $this->freshRank($inv));
    }

    public function test_losing_verification_flags_the_investor_for_review(): void
    {
        $this->loadProposed();
        $inv = $this->investorWith(5000, 3);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('gold', $this->freshRank($inv));

        $inv->update(['kyc_status' => 'rejected', 'kyc_verified' => false]);
        $this->engine()->evaluateUser($inv->id);

        $this->assertSame('gold', $this->freshRank($inv));
        $this->assertStringContainsString('verified', json_encode(RankDowngradeReview::open()->where('investor_id', $inv->id)->sole()->failed_conditions));
    }

    // ── Manual assignment honours the same prerequisites ────────────────────────────

    protected function assign(User $u, string $rank, array $extra = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            array_merge(['rank_id' => $this->rank($rank)->id, 'reason' => 'Management decision'], $extra));
    }

    public function test_manual_assignment_to_an_ineligible_account_is_refused_by_default(): void
    {
        foreach ([['status' => 'suspended'], ['kyc_status' => 'pending', 'kyc_verified' => false]] as $attrs) {
            $inv = $this->investor($attrs);
            $this->assign($inv, 'gold')->assertStatus(422)->assertJsonValidationErrors('override');
            $this->assertNull($this->freshRank($inv));
        }
        $this->assertSame(0, InvestorRankChange::count());
    }

    public function test_authorized_manual_exception_for_an_ineligible_account_is_justified_and_audited(): void
    {
        config(['ranks.allow_manual_override' => true]);
        $inv = $this->investor(['kyc_status' => 'pending', 'kyc_verified' => false]);

        $this->assign($inv, 'gold')->assertStatus(422)->assertJsonValidationErrors('override');
        $this->assign($inv, 'gold', ['override' => true, 'override_justification' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('override_justification');
        $this->assign($inv, 'gold', ['override' => true, 'override_justification' => 'Verification in progress; approved by Compliance'])->assertOk();

        $row = InvestorRankChange::where('investor_id', $inv->id)->sole();
        $this->assertSame('authorized_override', $row->change_type);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertFalse((bool) $row->is_automatic);
        $this->assertNotEmpty($row->override_justification);
        $this->assertFalse($row->criteria_snapshot['prerequisites']['eligible']);

        // what Admin just approved must not raise a review alert, nor be reversed
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('gold', $this->freshRank($inv));
        $this->assertSame(0, RankDowngradeReview::open()->count());
        $this->assertSame(1, InvestorRankChange::where('investor_id', $inv->id)->count());
    }

    public function test_manual_assignment_works_for_an_eligible_investor_and_is_recorded(): void
    {
        $inv = $this->investor();
        $this->assign($inv, 'silver', ['reason' => 'Goodwill classification approved by Management'])->assertOk();

        $row = InvestorRankChange::where('investor_id', $inv->id)->sole();
        $this->assertSame('manual_assignment', $row->change_type);
        $this->assertSame('Goodwill classification approved by Management', $row->reason);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertNotNull($row->created_at);
        $this->assertSame($inv->account_id, $row->account_id);
    }

    // ── Admin controls everything through the dashboard API ─────────────────────────

    public function test_admin_can_change_a_threshold_without_code_and_it_takes_effect(): void
    {
        $this->loadProposed();
        $this->actingAs($this->admin, 'sanctum');

        $below = $this->investorWith(4500, 3);                                    // Silver today
        $this->engine()->evaluateUser($below->id);
        $this->assertSame('silver', $this->freshRank($below));

        $goldId = RankCriterion::where('investor_rank_id', $this->rank('gold')->id)->value('id');
        $this->putJson("/api/admin/ranks/criteria/{$goldId}", [
            'name' => 'Gold (updated by Admin)', 'logic' => 'all', 'reason' => 'Management revised the threshold',
            'conditions' => [
                $this->cond('total_invested', '>=', 4000),
                $this->cond('completed_investment_count', '>=', 3),
            ],
        ])->assertOk();

        $this->engine()->evaluateUser($below->id);
        $this->assertSame('gold', $this->freshRank($below));                      // upgraded after the Admin's edit

        $h = RankCriteriaHistory::where('rank_criterion_id', $goldId)->where('action', 'updated')->sole();
        $this->assertEquals(5000, $h->previous['conditions'][0]['value']);
        $this->assertEquals(4000, $h->new['conditions'][0]['value']);
        $this->assertSame($this->admin->id, $h->actor_id);
    }

    public function test_raising_a_threshold_never_downgrades_anyone_automatically(): void
    {
        $this->loadProposed();
        $this->actingAs($this->admin, 'sanctum');
        $inv = $this->investorWith(5000, 3);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('gold', $this->freshRank($inv));

        $goldId = RankCriterion::where('investor_rank_id', $this->rank('gold')->id)->value('id');
        $this->putJson("/api/admin/ranks/criteria/{$goldId}", [
            'name' => 'Gold', 'logic' => 'all',
            'conditions' => [$this->cond('total_invested', '>=', 8000), $this->cond('completed_investment_count', '>=', 3)],
        ])->assertOk();
        $this->engine()->evaluateUser($inv->id);

        $this->assertSame('gold', $this->freshRank($inv));
        $this->assertSame(1, RankDowngradeReview::open()->where('investor_id', $inv->id)->count());
    }

    public function test_admin_can_deactivate_and_reactivate_a_proposed_rule(): void
    {
        $this->loadProposed();
        $this->actingAs($this->admin, 'sanctum');
        $id = RankCriterion::where('investor_rank_id', $this->rank('elite')->id)->value('id');

        $this->postJson("/api/admin/ranks/criteria/{$id}/deactivate", ['reason' => 'Pending final approval'])->assertOk();
        $inv = $this->investorWith(60000, 14);
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('diamond', $this->freshRank($inv));                     // Elite no longer attainable

        $this->postJson("/api/admin/ranks/criteria/{$id}/activate")->assertOk();
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('elite', $this->freshRank($inv));
    }

    public function test_builder_exposes_investment_amount_and_completed_cycles_in_usd(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/criteria/schema')->assertOk();
        $fields = collect($res->json('fields'))->keyBy('key');

        $this->assertSame('Completed investment cycles', $fields['completed_investment_count']['label']);
        $this->assertSame('integer', $fields['completed_investment_count']['type']);
        $this->assertSame('USD', $fields['total_invested']['unit']);                       // the ranking currency is fixed
        $this->assertStringContainsString('verified', strtolower($fields['total_invested']['label']));
        $this->assertContains('>=', $fields['total_invested']['operators']);
        $this->assertContains('between', $fields['total_invested']['operators']);          // hard upper limits possible
    }

    public function test_a_hard_upper_band_can_be_configured_in_the_dashboard_if_management_wants_it(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->createCriterion('bronze', [
            $this->cond('total_invested', 'between', [100, 999]),
            $this->cond('completed_investment_count', '>=', 1),
        ]);

        $in  = $this->investorWith(500, 1);
        $out = $this->investorWith(1500, 1);                                      // above the band
        $this->engine()->evaluateUser($in->id);
        $this->engine()->evaluateUser($out->id);

        $this->assertSame('bronze', $this->freshRank($in));
        $this->assertNull($this->freshRank($out));
    }

    // ── Repeatability, audit, notifications, financial separation ──────────────────

    public function test_repeated_evaluation_creates_one_record_and_one_notification_per_admin(): void
    {
        $second = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inv = $this->investorWith(10000, 5);        // data first, THEN the rules → one jump, one record
        $this->loadProposed();

        for ($i = 0; $i < 6; $i++) $this->engine()->evaluateUser($inv->id);
        $change = InvestorRankChange::where('investor_id', $inv->id)->sole();
        for ($i = 0; $i < 3; $i++) app(RankNotifier::class)->deliverChange($change->id);

        $this->assertSame('platinum', $this->freshRank($inv));
        $this->assertSame(1, InvestorRankChange::where('investor_id', $inv->id)->count());
        foreach ([$this->admin, $second] as $a) {
            $this->assertSame(1, $a->notifications()->where('data->type', 'rank_upgrade')->count());
        }
        $d = $this->admin->notifications()->where('data->type', 'rank_upgrade')->first()->data;
        $this->assertSame('Platinum', $d['new_rank']);
        $this->assertTrue($d['automatic']);
    }

    public function test_automatic_upgrade_audit_record_names_the_rule_and_values_that_justified_it(): void
    {
        $inv = $this->investorWith(20000, 8);        // data first, THEN the rules → a single audit record
        $this->loadProposed();
        $this->engine()->evaluateUser($inv->id);

        $row = InvestorRankChange::where('investor_id', $inv->id)->sole();
        $this->assertSame('automatic_upgrade', $row->change_type);
        $this->assertTrue($row->is_automatic);
        $this->assertNull($row->actor_id);
        $this->assertSame('System (Rank Engine)', $row->actor_name);
        $this->assertSame($this->rank('diamond')->id, $row->new_rank_id);
        $this->assertNull($row->previous_rank_id);
        $this->assertTrue($row->criteria_snapshot['prerequisites']['eligible'] ?? true);
        $conds = collect($row->criteria_snapshot['evaluation']['conditions'])->keyBy('field');
        $this->assertEquals(20000, $conds['total_invested']['actual']);
        $this->assertSame(8, $conds['completed_investment_count']['actual']);
        $this->assertTrue($conds['total_invested']['passed'] && $conds['completed_investment_count']['passed']);
    }

    public function test_ranks_never_change_balances_profits_withdrawals_or_financial_records(): void
    {
        $inv = $this->investorWith(50000, 12);
        DB::table('users')->where('id', $inv->id)->update(['balance' => 777.77]);

        $tables = array_values(array_filter(['deposits', 'withdrawals', 'balance_adjustments', 'financial_audit_logs', 'transactions'],
            fn ($t) => Schema::hasTable($t)));
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $invBefore = DB::table('investment_accounts')->where('user_id', $inv->id)->orderBy('id')->get(['id', 'amount', 'expected_profit', 'total_return', 'status', 'is_paid']);

        $this->loadProposed();                                   // rules go live AFTER the snapshot
        $this->engine()->evaluateUser($inv->id);
        $this->assertSame('elite', $this->freshRank($inv));

        $this->assertEquals(777.77, (float) $inv->fresh()->balance);
        foreach ($tables as $t) $this->assertSame($before[$t], DB::table($t)->count(), "$t changed");
        $this->assertEquals($invBefore->toArray(), DB::table('investment_accounts')->where('user_id', $inv->id)->orderBy('id')->get(['id', 'amount', 'expected_profit', 'total_return', 'status', 'is_paid'])->toArray());
    }

    public function test_badge_data_follows_the_rank_for_profile_dashboard_and_admin_views(): void
    {
        $this->loadProposed();
        $inv = $this->investorWith(5000, 3);
        $this->engine()->evaluateUser($inv->id);

        $this->actingAs($inv->fresh(), 'sanctum');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('rank.key', 'gold');
        $this->getJson('/api/investor-investment/rank')->assertOk()->assertJsonPath('rank.key', 'gold');
        $this->getJson('/api/investor-investment/tasks')->assertOk()->assertJsonPath('investor.rank.key', 'gold');

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson("/api/admin/users/{$inv->id}")->assertOk()->assertJsonPath('user.rank.key', 'gold');
    }

    public function test_non_admins_cannot_load_or_change_criteria_via_the_api(): void
    {
        $this->loadProposed();
        $id = RankCriterion::first()->id;
        $before = RankCriterion::find($id)->version;

        foreach ([$this->investor(), User::factory()->create(['role' => 'financial', 'status' => 'active'])] as $u) {
            $this->actingAs($u, 'sanctum');
            $this->putJson("/api/admin/ranks/criteria/{$id}", ['name' => 'x', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 1)]])->assertStatus(403);
            $this->postJson("/api/admin/ranks/criteria/{$id}/deactivate")->assertStatus(403);
        }
        $this->assertSame($before, RankCriterion::find($id)->version);
    }
}
