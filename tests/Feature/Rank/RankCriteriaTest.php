<?php
// LOCATION: tests/Feature/Rank/RankCriteriaTest.php

namespace Tests\Feature\Rank;

use App\Models\RankCriteriaHistory;
use App\Models\RankCriterion;
use App\Models\User;

class RankCriteriaTest extends RankTestCase
{
    public function test_admin_can_create_edit_activate_and_deactivate_criteria_for_all_six_ranks(): void
    {
        $thresholds = ['bronze' => 100, 'silver' => 200, 'gold' => 300, 'platinum' => 400, 'diamond' => 500, 'elite' => 600];
        $ids = [];
        foreach ($thresholds as $key => $amount) {
            $out = $this->createCriterion($key, [$this->cond('total_invested', '>=', $amount)]);
            $ids[$key] = $out['criterion']['id'];
            $this->assertTrue($out['criterion']['is_active']);
        }
        $this->assertSame(6, RankCriterion::where('is_active', true)->count());

        // edit (raise Gold) — persisted, version bumped
        $this->putJson("/api/admin/ranks/criteria/{$ids['gold']}", [
            'name' => 'Gold v2', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 350)],
        ])->assertOk()->assertJsonPath('criterion.name', 'Gold v2');
        $this->assertGreaterThan(1, RankCriterion::find($ids['gold'])->version);

        // deactivate / reactivate
        $this->postJson("/api/admin/ranks/criteria/{$ids['gold']}/deactivate", ['reason' => 'Pausing'])->assertOk();
        $this->assertFalse(RankCriterion::find($ids['gold'])->is_active);
        $this->postJson("/api/admin/ranks/criteria/{$ids['gold']}/activate")->assertOk();
        $this->assertTrue(RankCriterion::find($ids['gold'])->is_active);
    }

    public function test_criteria_changes_are_recorded_with_admin_before_and_after(): void
    {
        $out = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 500)], ['reason' => 'Management approved']);
        $id = $out['criterion']['id'];
        $this->putJson("/api/admin/ranks/criteria/{$id}", [
            'name' => 'Gold', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 750)], 'reason' => 'Raised',
        ])->assertOk();

        $h = RankCriteriaHistory::where('rank_criterion_id', $id)->orderBy('id')->get();
        $this->assertSame(['created', 'activated', 'updated'], $h->pluck('action')->all());
        $this->assertTrue($h->every(fn ($r) => $r->actor_id === $this->admin->id));

        $upd = $h->last();
        $this->assertEquals(500, $upd->previous['conditions'][0]['value']);
        $this->assertEquals(750, $upd->new['conditions'][0]['value']);
        $this->assertSame('Raised', $upd->reason);
    }

    public function test_activating_replaces_the_previous_active_rule_for_that_rank_and_keeps_it_in_history(): void
    {
        $first = $this->createCriterion('silver', [$this->cond('total_invested', '>=', 100)])['criterion']['id'];
        $second = $this->createCriterion('silver', [$this->cond('total_invested', '>=', 150)], ['name' => 'Replacement'])['criterion']['id'];

        $this->assertFalse(RankCriterion::find($first)->is_active);
        $this->assertTrue(RankCriterion::find($second)->is_active);
        $this->assertSame(1, RankCriterion::where('investor_rank_id', $this->rank('silver')->id)->where('is_active', true)->count());
        $this->assertTrue(RankCriteriaHistory::where('rank_criterion_id', $first)->where('action', 'deactivated')->exists());
    }

    public function test_removing_criteria_archives_it_requires_a_reason_and_keeps_history(): void
    {
        $id = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 500)])['criterion']['id'];

        $this->deleteJson("/api/admin/ranks/criteria/{$id}")->assertStatus(422);
        $this->deleteJson("/api/admin/ranks/criteria/{$id}", ['reason' => 'Superseded by new policy'])->assertOk();

        $this->assertNull(RankCriterion::find($id));
        $this->assertNotNull(RankCriterion::withTrashed()->find($id));
        $this->assertTrue(RankCriteriaHistory::where('rank_criterion_id', $id)->where('action', 'archived')->exists());
    }

    public function test_invalid_configurations_are_rejected_with_meaningful_messages(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $bad = [
            'unsupported field'   => [$this->cond('wallet_balance', '>=', 5)],
            'operator not valid for type' => [$this->cond('kyc_verified', '>=', true)],
            'missing value'       => [$this->cond('total_invested', '>=', '')],
            'non numeric'         => [$this->cond('total_invested', '>=', 'abc')],
            'negative'            => [$this->cond('total_invested', '>=', -5)],
            'fraction for integer field' => [$this->cond('investment_count', '>=', 1.5)],
            'bad enum option'     => [$this->cond('kyc_status', '=', 'banana')],
            'between min > max'   => [$this->cond('total_invested', 'between', [500, 100])],
            'contradiction'       => [$this->cond('total_invested', '>=', 500), $this->cond('total_invested', '<', 100)],
            'duplicate'           => [$this->cond('total_invested', '>=', 500), $this->cond('total_invested', '>=', 500)],
        ];
        foreach ($bad as $label => $conditions) {
            $res = $this->postJson('/api/admin/ranks/criteria', $this->payload('gold', $conditions));
            $this->assertSame(422, $res->status(), "Expected rejection for: $label");
            $this->assertNotEmpty($res->json('errors'), "Expected a message for: $label");
        }
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_a_criterion_with_no_conditions_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/ranks/criteria', $this->payload('gold', []))->assertStatus(422);
    }

    public function test_higher_rank_easier_than_lower_rank_is_flagged_as_a_conflict_not_applied(): void
    {
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 1000)]);

        $res = $this->postJson('/api/admin/ranks/criteria', $this->payload('gold', [$this->cond('total_invested', '>=', 500)]));
        $res->assertStatus(422);
        $this->assertStringContainsString('Invalid rank ordering', implode(' ', $res->json('errors.conflicts')));
        $this->assertFalse(RankCriterion::where('investor_rank_id', $this->rank('gold')->id)->exists());
    }

    public function test_identical_rules_on_two_ranks_are_an_ambiguity_conflict(): void
    {
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 1000)]);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/ranks/criteria', $this->payload('gold', [$this->cond('total_invested', '>=', 1000)]))
            ->assertStatus(422);
    }

    public function test_a_conflicting_draft_can_be_saved_inactive_but_not_activated(): void
    {
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 1000)]);
        $draft = $this->postJson('/api/admin/ranks/criteria',
            $this->payload('gold', [$this->cond('total_invested', '>=', 500)], ['is_active' => false]));
        $draft->assertCreated();

        $this->postJson('/api/admin/ranks/criteria/' . $draft->json('criterion.id') . '/activate')->assertStatus(422);
    }

    public function test_grouped_logic_any_combination_is_supported_and_validated(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $ok = $this->postJson('/api/admin/ranks/criteria', $this->payload('gold', [
            $this->cond('total_invested', '>=', 500, 1), $this->cond('kyc_verified', '=', true, 1),
            $this->cond('investment_count', '>=', 3, 2),
        ], ['logic' => 'grouped']));
        $ok->assertCreated();

        $badGroup = $this->postJson('/api/admin/ranks/criteria', $this->payload('platinum', [
            $this->cond('total_invested', '>=', 900, 1), $this->cond('total_invested', '<', 10, 1),
        ], ['logic' => 'grouped']));
        $badGroup->assertStatus(422);
    }

    public function test_validate_endpoint_reports_without_saving(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/criteria/validate',
            $this->payload('gold', [$this->cond('total_invested', '>=', 500)]));
        $res->assertOk()->assertJsonPath('valid', true);
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_schema_exposes_only_supported_fields_and_operators(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/criteria/schema')->assertOk();
        $keys = collect($res->json('fields'))->pluck('key')->all();
        $this->assertContains('total_invested', $keys);
        $this->assertNotContains('balance', $keys);
        $this->assertNotContains('password', $keys);
    }

    public function test_non_admins_cannot_manage_criteria_or_read_history(): void
    {
        $body = $this->payload('gold', [$this->cond('total_invested', '>=', 500)]);

        foreach ([$this->investor(), User::factory()->create(['role' => 'financial', 'status' => 'active'])] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->postJson('/api/admin/ranks/criteria', $body)->assertStatus(403);
            $this->getJson('/api/admin/ranks/criteria')->assertStatus(403);
            $this->getJson('/api/admin/ranks/criteria/history')->assertStatus(403);
            $this->getJson('/api/admin/ranks/audit')->assertStatus(403);
        }
        $this->assertSame(0, RankCriterion::count());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/admin/ranks')->assertStatus(401);
    }

    public function test_activating_criteria_queues_an_evaluation_run_with_status(): void
    {
        $this->investor();
        $out = $this->createCriterion('gold', [$this->cond('total_invested', '>=', 500)]);
        $this->assertNotNull($out['run']);
        $this->assertContains($out['run']['status'], ['pending', 'running', 'completed']);
    }
}
