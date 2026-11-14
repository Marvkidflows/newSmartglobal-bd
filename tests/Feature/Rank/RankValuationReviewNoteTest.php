<?php
// LOCATION: tests/Feature/Rank/RankValuationReviewNoteTest.php
//
// Management clarification 2 (7 Nov 2026): when a possible rank shortfall may be caused by an unverified, stale or unresolved currency
// valuation, the review shown to administrators (and the staff alert) must say so, say that Financial verification is needed, and must NOT
// present the investor as having definitively failed. The rank stays unchanged; nothing is valued at a guess or counted as zero silently.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php -d memory_limit=-1 vendor\bin\pest --filter=RankValuationReviewNoteTest

namespace Tests\Feature\Rank;

use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\RankDowngradeReview;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\RankNotifier;
use Illuminate\Support\Facades\DB;

class RankValuationReviewNoteTest extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->financialRulesOn();
    }

    private function rankKey(User $u): ?string
    {
        $id = $u->fresh()->investor_rank_id;
        return $id ? InvestorRank::find($id)->key : null;
    }

    private function evaluate(User $u): void
    {
        app(RankEvaluationService::class)->evaluateUser($u->id);
    }

    private function silverHolder(): array
    {
        $u = $this->cash($this->investor(), 0);
        $pos = $this->invest($u, 1000, 30, 'active');
        $this->createCriterion('silver', [$this->cond('current_eligible_principal', '>=', 500)]);
        $this->evaluate($u);
        $this->assertSame('silver', $this->rankKey($u));
        return [$u, $pos];
    }

    private function goStale($pos, int $amount): void
    {
        DB::table('investment_accounts')->where('id', $pos->id)->update(['amount' => $amount]);
    }

    private function adminGet(string $uri)
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson($uri);
    }

    public function test_a_stale_valuation_review_says_the_shortfall_may_be_valuation_related_and_needs_financial_verification(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);

        $list = $this->adminGet('/api/admin/ranks/reviews')->assertOk();
        $note = $list->json('data.0.valuation_note');
        $this->assertTrue($note['valuation_related']);
        $this->assertSame(1, $note['records']);
        $this->assertSame(1, $note['stale']);
        $this->assertSame(0, $note['unverified']);
        $this->assertSame(0, $note['unresolved']);
        $this->assertSame('Financial verification', $note['requires']);
        foreach ([
            'may be valuation-related', 'unverified, stale or unresolved', 'not counted as zero', 'no exchange rate has been assumed',
            'Financial verification is required before deciding', 'does not mean the investor has definitively failed', "rank has not been changed",
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $note['message']);
        }

        // the same explanation on the investor's own admin page
        $detail = $this->adminGet("/api/admin/ranks/investors/{$u->id}")->assertOk();
        $this->assertSame(1, $detail->json('open_review.valuation_note.records'));

        // nothing was changed or guessed
        $this->assertSame('silver', $this->rankKey($u));
        $this->assertSame(0, InvestorRankChange::where('investor_id', $u->id)->where('change_type', 'downgrade')->count());
        $this->assertSame('1001.00', number_format((float) DB::table('investment_accounts')->where('id', $pos->id)->value('amount'), 2, '.', ''), 'the record is untouched');
    }

    public function test_a_missing_valuation_is_explained_the_same_way(): void
    {
        $u = $this->cash($this->investor(), 0);
        $u->forceFill(['investor_rank_id' => $this->rank('silver')->id, 'rank_status' => 'active'])->save();
        $this->invest($u, 1000, 30, 'active', false);        // recorded, never verified: no valuation at all
        $this->createCriterion('silver', [$this->cond('current_eligible_principal', '>=', 500)]);
        $this->evaluate($u);

        $note = $this->adminGet('/api/admin/ranks/reviews')->assertOk()->json('data.0.valuation_note');
        $this->assertSame(1, $note['unverified']);
        $this->assertSame(0, $note['stale']);
        $this->assertStringContainsString('may be valuation-related', $note['message']);
        $this->assertSame('silver', $this->rankKey($u->fresh()));
    }

    public function test_a_genuine_shortfall_has_no_valuation_note_and_keeps_the_plain_wording(): void
    {
        $u = $this->cash($this->investor(), 0);
        $this->invest($u, 2000, 30, 'active');                // fully verified
        $gold = $this->createCriterion('gold', [$this->cond('current_eligible_principal', '>=', 1000)])['criterion']['id'];
        $this->evaluate($u);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/ranks/criteria/{$gold}", ['name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('current_eligible_principal', '>=', 9000)]])->assertOk();
        $this->evaluate($u);

        $review = RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->firstOrFail();
        $this->assertNull($this->adminGet('/api/admin/ranks/reviews')->assertOk()->json('data.0.valuation_note'));

        app(RankNotifier::class)->deliverReview($review->id);
        $data = $this->admin->notifications()->where('data->type', 'rank_downgrade_review')->firstOrFail()->data;
        $this->assertStringContainsString('no longer meets the criteria', $data['message']);
        $this->assertFalse($data['valuation_related']);
        $this->assertNull($data['valuation_note']);
    }

    public function test_the_staff_alert_for_a_valuation_review_does_not_say_the_investor_failed(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->firstOrFail();

        app(RankNotifier::class)->deliverReview($review->id);
        $data = $this->admin->notifications()->where('data->type', 'rank_downgrade_review')->firstOrFail()->data;

        $this->assertStringContainsString('may no longer meet', $data['message']);
        $this->assertStringContainsString('valuation-related', $data['message']);
        $this->assertStringContainsString('Financial verification is required', $data['message']);
        $this->assertStringContainsString('No change has been made', $data['message']);
        $this->assertStringNotContainsString('no longer meets the criteria', $data['message']);
        $this->assertTrue($data['valuation_related']);
        $this->assertStringContainsString('not counted as zero', $data['valuation_note']);
    }

    public function test_after_financial_verifies_the_valuation_the_review_closes_and_the_note_goes_away(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->firstOrFail();

        $this->verifyUsd($pos->fresh());
        $this->evaluate($u);

        $this->assertSame('cleared', $review->fresh()->status);
        $this->assertSame('silver', $this->rankKey($u));
        $all = $this->adminGet('/api/admin/ranks/reviews?status=all')->assertOk()->json('data');
        $row = collect($all)->firstWhere('id', $review->id);
        $this->assertNull($row['valuation_note'], 'a closed review carries no valuation note');
        $this->assertNotEmpty($row['resolution_reason']);
    }

    public function test_a_decided_review_carries_no_note_and_the_note_does_not_change_who_may_decide(): void
    {
        [$u, $pos] = $this->silverHolder();
        $this->goStale($pos, 1001);
        $this->evaluate($u);
        $review = RankDowngradeReview::where('investor_id', $u->id)->where('status', 'open')->firstOrFail();

        // Financial staff may read the problem and verify records, but receive 403 on the decision.
        $this->actingAs($this->fin, 'sanctum')->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Financial staff trying'])->assertStatus(403);
        $this->assertSame('open', $review->fresh()->status);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/reviews/{$review->id}/retain", ['reason' => 'Valuation being verified by Financial'])->assertOk();
        $row = collect($this->adminGet('/api/admin/ranks/reviews?status=all')->assertOk()->json('data'))->firstWhere('id', $review->id);
        $this->assertSame('retained', $row['status']);
        $this->assertNull($row['valuation_note']);
        $this->assertSame('silver', $this->rankKey($u));
    }
}
