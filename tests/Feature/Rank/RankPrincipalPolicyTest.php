<?php
// LOCATION: tests/Feature/Rank/RankPrincipalPolicyTest.php
//
// Replaces the superseded "investment basis" tests. The Board decided the measure (current verified eligible principal); this gate makes
// sure a money rank rule cannot be ACTIVATED until the Financial controls are enabled and the formal sign-offs are on record.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php artisan test --filter=RankPrincipalPolicyTest

namespace Tests\Feature\Rank;

use App\Models\RankBenefitAudit;
use App\Models\RankCriterion;
use App\Models\RankPolicyDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class RankPrincipalPolicyTest extends RankTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ranks.require_principal_policy' => true]);   // the REAL default: the gate is on
        config(['financial.separate_deposit_approval' => false, 'financial.ledger_enabled' => false]);   // the shipped defaults
    }

    private function money(): array
    {
        return [$this->cond('current_eligible_principal', '>=', 100), $this->cond('completed_investment_count', '>=', 1)];
    }

    private function tryActivate(array $conditions)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria', $this->payload('bronze', $conditions));
    }

    private function record(string $topic, string $status = 'confirmed', string $ref = 'SSI-APPROVAL-2026-01', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson('/api/admin/ranks/principal-policy', ['topic' => $topic, 'status' => $status, 'approval_reference' => $ref, 'note' => 'Written approval on file']);
    }

    private function openEverything(): void
    {
        config(['financial.separate_deposit_approval' => true, 'financial.ledger_enabled' => true]);
        $this->record('financial_decisions_adopted')->assertCreated();
        $this->record('release_authorization')->assertCreated();
    }

    public function test_by_default_a_rule_measuring_current_principal_cannot_be_activated(): void
    {
        $res = $this->tryActivate($this->money());
        $res->assertStatus(422);
        $this->assertStringContainsString('principal_policy', json_encode($res->json()));
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
    }

    public function test_a_draft_and_a_cycles_only_rule_are_not_affected(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria', $this->payload('bronze', $this->money(), ['is_active' => false]))->assertSuccessful();
        $this->tryActivate([$this->cond('completed_investment_count', '>=', 1)])->assertSuccessful();
        $this->assertSame(1, RankCriterion::where('is_active', true)->count());
    }

    public function test_the_sign_offs_alone_are_not_enough_while_the_financial_controls_are_off(): void
    {
        $this->record('financial_decisions_adopted')->assertCreated();
        $this->record('release_authorization')->assertCreated();
        $this->tryActivate($this->money())->assertStatus(422);
    }

    public function test_the_controls_alone_are_not_enough_without_the_sign_offs(): void
    {
        config(['financial.separate_deposit_approval' => true, 'financial.ledger_enabled' => true]);
        $this->tryActivate($this->money())->assertStatus(422);
        $this->record('financial_decisions_adopted')->assertCreated();
        $this->tryActivate($this->money())->assertStatus(422);   // release still missing
    }

    public function test_with_controls_on_and_both_sign_offs_the_gate_opens(): void
    {
        $this->openEverything();
        $this->tryActivate($this->money())->assertSuccessful();
    }

    public function test_revoking_a_sign_off_closes_the_gate_for_new_activations_only(): void
    {
        $this->openEverything();
        $this->tryActivate($this->money())->assertSuccessful();
        $this->record('release_authorization', 'revoked', 'SSI-REVOKE-2026-02')->assertCreated()->assertJsonPath('allows_activation', false);
        $this->assertSame(1, RankCriterion::where('is_active', true)->count(), 'nothing already active is changed');
    }

    public function test_only_an_admin_can_record_a_sign_off_and_a_reference_is_mandatory(): void
    {
        $this->record('release_authorization', 'confirmed', 'SSI-APPROVAL-2026-01', User::factory()->create(['role' => 'financial', 'status' => 'active']))->assertStatus(403);
        $this->record('release_authorization', 'confirmed', 'x')->assertStatus(422);
        $this->assertSame(0, RankPolicyDecision::count());
    }

    public function test_sign_offs_are_append_only_and_audited(): void
    {
        $this->record('release_authorization')->assertCreated();
        $row = RankPolicyDecision::firstOrFail();
        $this->assertTrue(RankBenefitAudit::where('subject_type', 'policy_decision')->where('action', 'confirmed')->where('actor_id', $this->admin->id)->exists());
        try { $row->update(['status' => 'revoked']); $this->fail('editable'); } catch (LogicException) { $this->assertTrue(true); }
        try { $row->delete(); $this->fail('deletable'); } catch (LogicException) { $this->assertTrue(true); }
    }

    public function test_the_overview_states_the_measure_the_flags_and_what_is_still_pending_formal_approval(): void
    {
        $r = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/benefits')->assertOk()->json('principal_policy');

        $this->assertTrue($r['required']);
        $this->assertFalse($r['allows_activation']);
        $this->assertFalse($r['flags']['crypto_rank_valuation_enabled']);
        $this->assertFalse($r['flags']['live_fx_enabled']);
        $this->assertStringContainsString('Wallet cash never counts', $r['measure']);
        $this->assertNotEmpty(array_filter($r['pending_formal_approval'], fn ($t) => str_contains($t, 'account-level redemption fallback')));
    }

    public function test_recording_a_sign_off_changes_no_balance_investment_or_rank(): void
    {
        $u = $this->investor();
        $this->invest($u, 500, 30, 'active');
        $before = [DB::table('investment_accounts')->get()->toJson(), (float) $u->fresh()->balance, DB::table('users')->where('id', $u->id)->value('investor_rank_id')];
        $this->record('release_authorization')->assertCreated();
        $this->assertSame($before, [DB::table('investment_accounts')->get()->toJson(), (float) $u->fresh()->balance, DB::table('users')->where('id', $u->id)->value('investor_rank_id')]);
    }
}
