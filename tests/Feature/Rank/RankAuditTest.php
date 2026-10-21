<?php
// LOCATION: tests/Feature/Rank/RankAuditTest.php

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankCriteriaHistory;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;

class RankAuditTest extends RankTestCase
{
    protected function seedHistory(): User
    {
        $u = $this->investor(['name' => 'Audit Subject']); $this->invest($u, 3000);
        $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)]);
        app(RankEvaluationService::class)->evaluateUser($u->id);                 // automatic
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            ['rank_id' => $this->rank('platinum')->id, 'reason' => 'Manual promotion by Management']); // manual
        return $u;
    }

    public function test_every_rank_change_has_an_audit_record_and_history_survives_later_changes(): void
    {
        $u = $this->seedHistory();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/downgrade",
            ['rank_id' => $this->rank('silver')->id, 'reason' => 'Review outcome']);

        $rows = InvestorRankChange::where('investor_id', $u->id)->orderBy('sequence')->get();
        $this->assertSame(['automatic_upgrade', 'manual_upgrade', 'downgrade'], $rows->pluck('change_type')->all());
        // chain is continuous: each row starts where the previous one ended
        $this->assertSame($rows[0]->new_rank_id, $rows[1]->previous_rank_id);
        $this->assertSame($rows[1]->new_rank_id, $rows[2]->previous_rank_id);
        $this->assertSame($this->rank('silver')->id, $u->fresh()->investor_rank_id);
    }

    public function test_automatic_and_manual_changes_are_distinguishable_and_filterable(): void
    {
        $this->seedHistory();
        $this->actingAs($this->admin, 'sanctum');

        $auto = $this->getJson('/api/admin/ranks/audit?automatic=1')->assertOk();
        $this->assertSame(1, $auto->json('meta.total'));
        $this->assertSame('System (Rank Engine)', $auto->json('data.0.actor_name'));
        $this->assertNull($auto->json('data.0.actor_id'));

        $manual = $this->getJson('/api/admin/ranks/audit?automatic=0')->assertOk();
        $this->assertSame($this->admin->id, $manual->json('data.0.actor_id'));

        $this->getJson('/api/admin/ranks/audit?actor=system')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ranks/audit?actor=' . $this->admin->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ranks/audit?change_type=manual_upgrade')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ranks/audit?rank_id=' . $this->rank('platinum')->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ranks/audit?investor=Audit%20Subject')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/ranks/audit?from=' . now()->toDateString() . '&to=' . now()->toDateString())->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_audit_records_carry_the_criteria_snapshot_and_reason(): void
    {
        $this->seedHistory();
        $row = InvestorRankChange::where('change_type', 'automatic_upgrade')->sole();

        $this->assertSame('total_invested', $row->criteria_snapshot['criterion']['conditions'][0]['field']);
        $this->assertSame(500, (int) $row->criteria_snapshot['criterion']['conditions'][0]['value']);
        $this->assertTrue($row->criteria_snapshot['evaluation']['conditions'][0]['passed']);
        $this->assertStringContainsString('Silver', $row->reason);
    }

    public function test_snapshot_still_explains_the_decision_after_the_criteria_change(): void
    {
        $u = $this->investor(); $this->invest($u, 3000);
        $id = $this->createCriterion('silver', [$this->cond('total_invested', '>=', 500)])['criterion']['id'];
        app(RankEvaluationService::class)->evaluateUser($u->id);
        $liveVersion = \App\Models\RankCriterion::find($id)->version;     // create (v1) + activate (v2)

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/ranks/criteria/{$id}",
            ['name' => 'Silver', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 99999)]])->assertOk();

        $row = InvestorRankChange::where('investor_id', $u->id)->sole();
        $this->assertSame(500, (int) $row->criteria_snapshot['criterion']['conditions'][0]['value']);   // rule AS APPLIED
        $this->assertSame($liveVersion, $row->criteria_snapshot['criterion']['version']);   // version AS APPLIED…
        $this->assertGreaterThan($liveVersion, \App\Models\RankCriterion::find($id)->version); // …not the later edit
    }

    public function test_audit_records_are_immutable(): void
    {
        $this->seedHistory();
        $row = InvestorRankChange::first();

        foreach ([fn () => $row->update(['reason' => 'tampered']), fn () => $row->delete(),
                  fn () => RankCriteriaHistory::first()->update(['actor_name' => 'x']),
                  fn () => RankCriteriaHistory::first()->delete()] as $attempt) {
            try { $attempt(); $this->fail('Audit record was modified.'); } catch (\LogicException $e) { $this->assertTrue(true); }
        }
        $this->assertNotSame('tampered', $row->fresh()->reason);
    }

    public function test_there_is_no_http_route_to_edit_or_delete_audit_records(): void
    {
        $this->seedHistory();
        $before = InvestorRankChange::count();
        $id = InvestorRankChange::first()->id;
        $this->actingAs($this->admin, 'sanctum');

        foreach (['put', 'patch', 'delete'] as $verb) {
            foreach (["/api/admin/ranks/audit/{$id}", '/api/admin/ranks/audit'] as $url) {
                $this->assertContains($this->{$verb . 'Json'}($url)->status(), [404, 405], "$verb $url must not exist");
            }
        }
        $this->assertSame($before, InvestorRankChange::count());
        $this->assertSame('automatic_upgrade', InvestorRankChange::first()->change_type);
    }

    public function test_unauthorized_users_cannot_read_rank_or_criteria_audit(): void
    {
        $this->seedHistory();
        foreach ([$this->investor(), User::factory()->create(['role' => 'financial', 'status' => 'active'])] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->getJson('/api/admin/ranks/audit')->assertStatus(403);
            $this->getJson('/api/admin/ranks/criteria/history')->assertStatus(403);
        }
    }

    public function test_criteria_history_is_filterable_and_shows_the_responsible_admin(): void
    {
        $this->seedHistory();
        $this->actingAs($this->admin, 'sanctum');

        $res = $this->getJson('/api/admin/ranks/criteria/history?action=created')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->admin->id, $res->json('data.0.actor_id'));
        $this->assertNotEmpty($res->json('data.0.new.conditions'));
        $this->getJson('/api/admin/ranks/criteria/history?rank_id=' . $this->rank('silver')->id)->assertOk();
    }
}
