<?php
// LOCATION: tests/Feature/Rank/RankDowngradeReviewPolicyTest.php
//
// Rank downgrade policy: REVIEW ONLY (Management decision, 7 Nov 2026). Automatic downgrade does not exist and must not be added.
//
// This file proves, in addition to the existing suite (RankEvaluationTest, RankManualChangeTest, RankNotificationTest, RankHoldTest):
//   1. pending withdrawals and unverified records cannot open a review or change a rank;
//   2. a stale/unresolved currency valuation opens a review for staff, never changes the rank, and re-verifying it recalculates and closes the review;
//   3. the same shortfall returning AFTER it was cleared is a new episode and alerts staff once more (regression fixed in RankEvaluationService);
//   4. retain and downgrade decisions record who, what, why and when; a decided review cannot be decided again;
//   5. only administrators decide: financial, marvflow, investor and unknown roles are denied;
//   6. automatic upgrades still work;
//   7. no automatic-downgrade write path, setting, command or scheduled job exists (a source-level guard);
//   8. (7 Nov 2026 clarifications) every admin may decide, Financial keeps upgrade approval but gets 403 on downgrade reviews, and no currency-rate age limit is applied.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php -d memory_limit=-1 vendor\bin\pest --filter=RankDowngradeReviewPolicyTest

namespace Tests\Feature\Rank;

use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\RankDowngradeReview;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankNotifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RankDowngradeReviewPolicyTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────────────

    private function rankKey(User $u): ?string
    {
        $id = $u->fresh()->investor_rank_id;
        return $id ? InvestorRank::find($id)->key : null;
    }

    private function evaluate(User $u): void
    {
        app(RankEvaluationService::class)->evaluateUser($u->id);
    }

    /** A Silver holder with $1,000 of verified principal under an active "principal >= 500" rule. */
    private function silverHolder(): array
    {
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 1000, 30, 'active');
        if (!\App\Models\RankCriterion::where('is_active', true)->exists()) {      // several holders may share the one rule
            $this->createCriterion('silver', [$this->cond('current_eligible_principal', '>=', 500)]);
        }
        $this->evaluate($u);
        $this->assertSame('silver', $this->rankKey($u), 'fixture: the investor holds Silver');
        return [$u, $pos];
    }

    /** Corrects the amount AFTER verification: the valuation is now stale and the position leaves the figures until it is re-verified. */
    private function goStale($pos, int $amount): void
    {
        DB::table('investment_accounts')->where('id', $pos->id)->update(['amount' => $amount]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function adminPost(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson($uri, $data);
    }

    private function openReview(User $u): RankDowngradeReview
    {
        return RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->firstOrFail();
    }

    // ── 1. pending withdrawals and unverified records ───────────────────────────────────────────────────

    public function test_a_pending_withdrawal_and_an_unverified_record_cannot_open_a_review_or_change_the_rank(): void
    {
        [$u, $pos] = $this->silverHolder();

        $w = $this->withdrawal($u, 800);
        $this->controls()->postJson("/api/financial/withdrawals/{$w->id}/classify", ['components' => [['component' => 'principal', 'amount' => 800, 'investment_id' => $pos->id]]])->assertCreated();
        $this->evaluate($u);

        $this->assertSame('silver', $this->rankKey($u));
        $this->assertSame(0, RankDowngradeReview::where('investor_id', $u->id)->count(), 'a pending withdrawal must not raise a downgrade review');
        $this->assertEquals(1000.0, $this->facts($u)['current_principal'], 'a pending withdrawal reduces no principal');

        $this->invest($u, 50, 30, 'active', false);   // recorded but NOT verified
        $this->evaluate($u);
        $this->assertSame('silver', $this->rankKey($u));
        $this->assertEquals(1000.0, $this->facts($u)['current_principal'], 'an unverified record adds nothing');
        $this->assertSame(0, RankDowngradeReview::where('investor_id', $u->id)->count());
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
    }

    // ── 2. stale or unresolved valuation: review, never a downgrade; re-verification recalculates ───────

    public function test_a_stale_valuation_opens_a_review_but_never_changes_the_rank_and_reverification_closes_it(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->assertEquals(0.0, $this->facts($u)['current_principal'], 'the unverified position is left out of the figures, not valued at a guess');
        $this->assertSame('1001.00', number_format((float) DB::table('investment_accounts')->where('id', $pos->id)->value('amount'), 2, '.', ''), 'the record itself is untouched');

        for ($i = 0; $i < 3; $i++) $this->evaluate($u);

        $this->assertSame('silver', $this->rankKey($u), 'the engine never downgrades on its own');
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
        $this->assertSame(1, RankDowngradeReview::where('investor_id', $u->id)->count(), 'exactly one review however often it is re-evaluated');
        $review = $this->openReview($u);
        $this->assertNotEmpty($review->failed_conditions);

        $this->verifyUsd($pos->fresh());      // Financial verifies the valuation
        $this->evaluate($u);

        $this->assertSame('silver', $this->rankKey($u));
        $review->refresh();
        $this->assertSame('cleared', $review->status);
        $this->assertNotNull($review->resolved_at);
        $this->assertNotEmpty($review->resolution_reason, 'the closing reason is recorded');
        $this->assertEquals(1001.0, $this->facts($u)['current_principal'], 'eligibility was recalculated with the verified valuation');
    }

    // ── 3. a recurring shortfall is a new episode (regression) and duplicates are still prevented ───────

    public function test_the_same_shortfall_returning_after_it_was_cleared_alerts_staff_again_once_per_episode(): void
    {
        [$u, $pos] = $this->silverHolder();
        $notifier = app(RankNotifier::class);
        $alerts = fn () => $this->admin->notifications()->where('data->type', 'rank_downgrade_review')->count();

        // episode 1
        $this->goStale($pos, 1001);
        for ($i = 0; $i < 2; $i++) $this->evaluate($u);
        $first = $this->openReview($u);
        $notifier->deliverReview($first->id); $notifier->deliverReview($first->id);
        $this->assertSame(1, $alerts());

        $this->verifyUsd($pos->fresh());
        $this->evaluate($u);
        $this->assertSame('cleared', $first->fresh()->status);

        // episode 2: the very same shortfall comes back
        $this->goStale($pos, 1002);
        for ($i = 0; $i < 3; $i++) $this->evaluate($u);
        $second = $this->openReview($u);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->fingerprint, $second->fingerprint);
        $this->assertSame(2, RankDowngradeReview::where('investor_id', $u->id)->count(), 'one review per episode, never more');
        $notifier->deliverReview($second->id); $notifier->deliverReview($second->id);
        $this->assertSame(2, $alerts(), 'one alert per episode');
        $this->assertSame('silver', $this->rankKey($u));

        // episode 3, and then a DECIDED review is not raised again
        $this->verifyUsd($pos->fresh());
        $this->evaluate($u);
        $this->goStale($pos, 1003);
        $this->evaluate($u);
        $third = $this->openReview($u);
        $this->assertSame(3, RankDowngradeReview::where('investor_id', $u->id)->count());
        $this->assertCount(3, RankDowngradeReview::where('investor_id', $u->id)->pluck('fingerprint')->unique());

        $this->adminPost("/api/admin/ranks/reviews/{$third->id}/retain", ['reason' => 'Valuation being re-verified'])->assertOk();
        for ($i = 0; $i < 3; $i++) $this->evaluate($u);
        $this->assertSame(3, RankDowngradeReview::where('investor_id', $u->id)->count(), 'a decided review is not re-raised for the same unchanged shortfall');
    }

    // ── 4. decisions are fully recorded; a decided review cannot be decided again ───────────────────────

    public function test_a_retain_decision_records_who_what_why_and_when_and_leaves_the_rank_alone(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = $this->openReview($u);

        $this->adminPost("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Currency record being re-verified'])->assertOk();

        $review->refresh();
        $this->assertSame('retained', $review->status);
        $this->assertSame($this->admin->id, $review->resolved_by);
        $this->assertNotEmpty($review->resolved_by_name);
        $this->assertSame('Currency record being re-verified', $review->resolution_reason);
        $this->assertNotNull($review->resolved_at);

        $change = InvestorRankChange::where('investor_id', $u->id)->orderByDesc('sequence')->first();
        $this->assertSame('review_retained', $change->change_type);
        $this->assertSame($this->admin->id, $change->actor_id);
        $this->assertSame('Currency record being re-verified', $change->reason);
        $this->assertSame($change->previous_rank_id, $change->new_rank_id, 'retaining changes no rank');
        $this->assertFalse((bool) $change->is_automatic);
        $this->assertNotNull($change->created_at);
        $this->assertSame('silver', $this->rankKey($u));
    }

    public function test_a_downgrade_decision_records_who_what_why_and_when_and_closes_the_review(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = $this->openReview($u);

        $this->adminPost("/api/admin/ranks/investors/{$u->id}/downgrade", ['rank_id' => $this->rank('bronze')->id, 'reason' => 'Reviewed: valuation not verified in time'])->assertOk();

        $this->assertSame('bronze', $this->rankKey($u));
        $review->refresh();
        $this->assertSame('downgraded', $review->status);
        $this->assertSame($this->admin->id, $review->resolved_by);
        $this->assertNotEmpty($review->resolved_by_name);
        $this->assertSame('Reviewed: valuation not verified in time', $review->resolution_reason);
        $this->assertNotNull($review->resolved_at);

        $change = InvestorRankChange::where('investor_id', $u->id)->orderByDesc('sequence')->first();
        $this->assertSame('downgrade', $change->change_type);
        $this->assertSame($this->rank('silver')->id, $change->previous_rank_id);
        $this->assertSame($this->rank('bronze')->id, $change->new_rank_id);
        $this->assertSame($this->admin->id, $change->actor_id);
        $this->assertSame('Reviewed: valuation not verified in time', $change->reason);
        $this->assertFalse((bool) $change->is_automatic, 'a downgrade is only ever a recorded human decision');
        $this->assertNotNull($change->created_at);
    }

    public function test_a_decided_review_cannot_be_decided_again(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = $this->openReview($u);

        $this->adminPost("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Decided once by an administrator'])->assertOk();
        $this->adminPost("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Decided a second time'])->assertStatus(422);
        $this->assertSame('Decided once by an administrator', $review->fresh()->resolution_reason, 'the first decision stands');
    }

    // ── 5. who may decide ───────────────────────────────────────────────────────────────────────────────

    public function test_only_administrators_decide_reviews(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = $this->openReview($u);

        // (A guest is refused with 401 by RankAuthorizationTest "no guest can reach any rank route"; actingAs() persists in this test, so it is not repeated here.)

        foreach (['financial', 'marvflow_member', 'marvflow_lead', 'investor'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($actor, 'sanctum')->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => "Attempt by {$role}"])->assertStatus(403);
            $this->actingAs($actor, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/downgrade", ['rank_id' => $this->rank('bronze')->id, 'reason' => "Attempt by {$role}"])->assertStatus(403);
        }
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Financial staff trying'])->assertStatus(403);

        $this->assertSame('open', $review->fresh()->status, 'no refused attempt changed the review');
        $this->assertSame('silver', $this->rankKey($u));
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
    }

    public function test_the_downgrade_abilities_are_denied_to_every_role_except_admin_including_unknown_ones(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->make(['role' => 'admin']))->allows('ranks.downgrade'));

        // "registration" and "registrar" do not exist as roles today: this proves a future or unknown staff role is denied by default.
        foreach (['financial', 'marvflow_member', 'marvflow_lead', 'investor', 'registration', 'registrar', ''] as $role) {
            $u = User::factory()->make(['role' => $role]);
            foreach (['ranks.downgrade', 'ranks.assign', 'ranks.override', 'ranks.suspend', 'ranks.revoke', 'ranks.manage-criteria'] as $ability) {
                $this->assertFalse(Gate::forUser($u)->allows($ability), "role '{$role}' must not hold {$ability}");
            }
        }

        // Financial staff keep what the policy gives them: verify records and approve or reject UPGRADE proposals, not decide downgrades.
        $fin = User::factory()->make(['role' => 'financial']);
        $this->assertTrue(Gate::forUser($fin)->allows('ranks.review'));
        $this->assertFalse(Gate::forUser($fin)->allows('ranks.downgrade'));
    }

    // ── Management clarifications (7 Nov 2026) ──────────────────────────────────────────────────────────

    public function test_every_existing_admin_account_can_decide_a_review_and_the_deciding_admin_is_recorded(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $adminB = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        [$u1, $pos1] = $this->silverHolder();
        [$u2, $pos2] = $this->silverHolder();
        $this->goStale($pos1, 1001);
        $this->goStale($pos2, 1001);
        $this->evaluate($u1);
        $this->evaluate($u2);
        $r1 = $this->openReview($u1);
        $r2 = $this->openReview($u2);

        $this->actingAs($adminA, 'sanctum')->postJson("/api/admin/ranks/reviews/{$r1->id}/retain", ['reason' => 'Retained by the first admin'])->assertOk();
        $this->actingAs($adminB, 'sanctum')->postJson("/api/admin/ranks/investors/{$u2->id}/downgrade", ['rank_id' => $this->rank('bronze')->id, 'reason' => 'Downgraded by the second admin'])->assertOk();

        $this->assertSame($adminA->id, $r1->fresh()->resolved_by);
        $this->assertSame('retained', $r1->fresh()->status);
        $this->assertSame($adminB->id, $r2->fresh()->resolved_by);
        $this->assertSame('downgraded', $r2->fresh()->status);
        $this->assertSame('silver', $this->rankKey($u1));
        $this->assertSame('bronze', $this->rankKey($u2));
        $this->assertSame($adminB->id, InvestorRankChange::where('investor_id', $u2->id)->orderByDesc('sequence')->first()->actor_id);
    }

    public function test_financial_staff_can_approve_an_upgrade_proposal_but_get_403_deciding_a_downgrade_review(): void
    {
        config(['ranks.require_approval' => true]);
        $this->createCriterion('bronze', [$this->cond('current_eligible_principal', '>=', 100), $this->cond('completed_investment_count', '>=', 1)]);
        $fin = $this->staff('financial');

        $a = $this->investorWith(100, 1);
        $this->evaluate($a);
        $proposal = \App\Models\RankReview::pending()->where('investor_id', $a->id)->firstOrFail();

        // existing authority: Financial approves an UPGRADE proposal
        $this->actingAs($fin, 'sanctum')->postJson("/api/financial/rank-approvals/{$proposal->id}/approve", [])->assertOk();
        $this->assertSame('bronze', $this->rankKey($a));

        // ...but cannot decide a DOWNGRADE review
        $position = \App\Models\InvestmentAccount::where('user_id', $a->id)->where('status', 'active')->firstOrFail();
        $this->goStale($position, (int) $position->amount + 1);
        $this->evaluate($a);
        $review = $this->openReview($a);

        $this->actingAs($fin, 'sanctum')->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Financial staff trying'])->assertStatus(403);
        $this->actingAs($fin, 'sanctum')->postJson("/api/admin/ranks/investors/{$a->id}/downgrade", ['rank_id' => $this->rank('bronze')->id, 'reason' => 'Financial staff trying'])->assertStatus(403);
        $this->assertSame('open', $review->fresh()->status);
        $this->assertSame('bronze', $this->rankKey($a));
    }

    public function test_a_verified_valuation_is_never_treated_as_stale_because_of_its_age(): void
    {
        // Management has NOT approved a maximum currency-rate age, so none is applied: staleness means "the record changed after verification".
        [$u, $pos] = $this->silverHolder();

        $this->travelTo(now()->addYears(5));
        $this->evaluate($u);

        $this->assertSame('silver', $this->rankKey($u));
        $this->assertEquals(1000.0, $this->facts($u)['current_principal'], 'an old verified valuation still counts');
        $this->assertSame(0, RankDowngradeReview::where('investor_id', $u->id)->count());
        $this->travelBack();
    }

    // ── 6. automatic upgrades continue to work ──────────────────────────────────────────────────────────

    public function test_automatic_upgrades_keep_working_and_a_stricter_rule_only_opens_a_review(): void
    {
        $u = $this->cash($this->investor(), 0);
        $this->invest($u, 600, 30, 'active');
        $this->createCriterion('silver', [$this->cond('current_eligible_principal', '>=', 500)]);
        $gold = $this->createCriterion('gold', [$this->cond('current_eligible_principal', '>=', 5000)])['criterion']['id'];

        $this->evaluate($u);
        $this->assertSame('silver', $this->rankKey($u), 'qualifies for Silver: upgraded automatically');

        $this->invest($u, 5000, 30, 'active');
        $this->evaluate($u);
        $this->assertSame('gold', $this->rankKey($u), 'qualifies for Gold: upgraded automatically');
        $this->assertSame(2, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'automatic_upgrade')->count());

        // the Gold rule is made stricter: the investor keeps Gold and a review opens
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('current_eligible_principal', '>=', 9000)]])->assertOk();
        $this->evaluate($u);
        $this->assertSame('gold', $this->rankKey($u));
        $this->assertSame(1, RankDowngradeReview::open()->where('investor_id', $u->id)->count());
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
    }

    // ── 7. no automatic downgrade path, setting, command or job exists ──────────────────────────────────

    public function test_there_is_no_automatic_downgrade_path_setting_command_or_scheduled_job(): void
    {
        // (a) Only these services write users.investor_rank_id.
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') continue;
            if (preg_match("/update\(\s*\[\s*'investor_rank_id'/", file_get_contents($file->getPathname()))) $writers[] = $file->getFilename();
        }
        sort($writers);
        $this->assertSame(['RankChangeService.php', 'RankEvaluationService.php', 'RankReviewService.php'], $writers,
            'a new place now writes a rank: review it against the review-only downgrade policy before accepting this change');

        // (b) The review sync never writes a rank.
        $src = file_get_contents(app_path('Services/Rank/RankEvaluationService.php'));
        $start = strpos($src, 'function syncDowngradeReview');
        $end = strpos($src, 'public function shortfall');
        $this->assertNotFalse($start); $this->assertNotFalse($end);
        $method = substr($src, $start, $end - $start);
        // The method may STORE the rank an investor holds as a field of the review row ('investor_rank_id' => ...). What it must never do is WRITE a rank.
        foreach ([
            '/update\(\s*\[\s*\'investor_rank_id\'/'               => 'an update() that sets investor_rank_id',
            '/forceFill\(\s*\[[^\]]*\'investor_rank_id\'/'         => 'a forceFill() that sets investor_rank_id',
            '/->investor_rank_id\s*=(?!=)/'                         => 'an assignment to ->investor_rank_id',
            '/setRawAttributes|applyAutomaticUpgrade|applyManual|recordChange/' => 'a call that changes a rank',
            '/InvestorRankChange::/'                                 => 'a rank-change record',
        ] as $pattern => $what) {
            $this->assertDoesNotMatchRegularExpression($pattern, $method, "syncDowngradeReview must not contain {$what}");
        }

        // (c) No setting for it.
        $this->assertDoesNotMatchRegularExpression('/downgrade|grace/i', implode(' ', array_keys(config('ranks'))), 'no downgrade or grace-period setting may exist');

        // (d) No command and nothing on the schedule mentions it.
        foreach (array_keys(Artisan::all()) as $name) {
            $this->assertDoesNotMatchRegularExpression('/downgrade/i', $name, "command {$name} looks like an automatic downgrade");
        }
        Artisan::call('schedule:list');
        $this->assertDoesNotMatchRegularExpression('/downgrade/i', Artisan::output());
    }
}
