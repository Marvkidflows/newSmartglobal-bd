<?php
// LOCATION: tests/Feature/Rank/RankFoundationTest.php

namespace Tests\Feature\Rank;

use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Models\User;

class RankFoundationTest extends RankTestCase
{
    public function test_six_ranks_exist_in_the_official_order(): void
    {
        $this->assertSame(
            ['bronze', 'silver', 'gold', 'platinum', 'diamond', 'elite'],
            InvestorRank::orderBy('level')->pluck('key')->all()
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], InvestorRank::orderBy('level')->pluck('level')->all());
    }

    public function test_no_criteria_exist_or_are_active_unless_someone_loads_them(): void
    {
        $this->assertSame(0, RankCriterion::withTrashed()->count());
        $this->assertNull($this->investor()->investorRank);
    }

    public function test_rank_is_not_mass_assignable(): void
    {
        $this->assertNotContains('investor_rank_id', (new User)->getFillable());
        $u = $this->investor();
        $u->update(['investor_rank_id' => $this->rank('elite')->id, 'name' => 'Changed']);
        $this->assertNull($u->fresh()->investor_rank_id);
    }

    public function test_account_id_matches_existing_financial_format_and_is_searchable(): void
    {
        $u = $this->investor(['name' => 'Searchable Person']);
        $expected = 'INVSTR-' . str_pad((string) $u->id, 5, '0', STR_PAD_LEFT);

        $this->assertSame($expected, $u->account_id);
        $this->assertSame($u->id, User::idFromAccountId($expected));

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/investors?search=' . $expected);
        $res->assertOk()->assertJsonPath('data.0.id', $u->id)->assertJsonPath('data.0.account_id', $expected);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/investors?search=Searchable')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_investor_list_can_be_filtered_by_rank(): void
    {
        $a = $this->investor(); $b = $this->investor();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$a->id}/assign",
            ['rank_id' => $this->rank('gold')->id, 'reason' => 'Initial classification'])->assertOk();

        $this->getJson('/api/admin/ranks/investors?rank=gold')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ranks/investors?rank=none')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_investor_task_list_and_history_carry_the_authoritative_rank(): void
    {
        $u = $this->investor(['name' => 'Task Person']);

        // Unranked → null (UI shows the "Unranked" fallback)
        $this->actingAs($u, 'sanctum')->getJson('/api/investor-investment/tasks')->assertOk()
            ->assertJsonPath('investor.name', 'Task Person')
            ->assertJsonPath('investor.account_id', $u->account_id)
            ->assertJsonPath('investor.rank', null);

        // Rank changes are reflected on the very next request — nothing client-supplied or cached.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            ['rank_id' => $this->rank('gold')->id, 'reason' => 'Initial classification'])->assertOk();
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/tasks')
            ->assertOk()->assertJsonPath('investor.rank.key', 'gold');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/downgrade",
            ['rank_id' => $this->rank('silver')->id, 'reason' => 'Account review outcome'])->assertOk();
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/tasks')
            ->assertOk()->assertJsonPath('investor.rank.key', 'silver')
            ->assertJsonStructure(['balance', 'tasks', 'summary', 'investor' => ['id', 'name', 'account_id', 'rank']]);
    }

    public function test_rank_is_returned_in_existing_investor_endpoints(): void
    {
        $u = $this->investor();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/ranks/investors/{$u->id}/assign",
            ['rank_id' => $this->rank('silver')->id, 'reason' => 'Initial classification'])->assertOk();

        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/investor-investment/rank')
            ->assertOk()->assertJsonPath('rank.key', 'silver');
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/user')
            ->assertOk()->assertJsonPath('rank.key', 'silver');
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/users/{$u->id}")
            ->assertOk()->assertJsonPath('user.rank.key', 'silver');
    }
}
