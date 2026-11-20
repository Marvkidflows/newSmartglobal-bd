<?php
// LOCATION: tests/Feature/Rank/RankActivationDefaultsTest.php   (NEW FILE — D-18)
// The SHIPPED defaults keep unapproved rank criteria inert: every safety switch defaults on, a database with no criteria ranks nobody, and loaded drafts
// (the Board's proposed thresholds) never change anyone's rank until an Admin activates them. WRITTEN, NOT EXECUTED (no vendor/).
// Run: php artisan test --filter=RankActivationDefaultsTest

namespace Tests\Feature\Rank;

use App\Models\RankCriterion;
use App\Services\Rank\RankEvaluationService;

class RankActivationDefaultsTest extends RankTestCase
{
    public function test_every_safety_switch_ships_on_and_manual_override_ships_off(): void
    {
        $src = file_get_contents(config_path('ranks.php'));
        $this->assertMatchesRegularExpression("/'require_approval'\s*=>\s*\(bool\)\s*env\('RANKS_REQUIRE_APPROVAL',\s*true\)/", $src);
        $this->assertMatchesRegularExpression("/'require_principal_policy'\s*=>\s*\(bool\)\s*env\('RANKS_REQUIRE_PRINCIPAL_POLICY',\s*true\)/", $src);
        $this->assertMatchesRegularExpression("/'enforce_core_requirements'\s*=>\s*\(bool\)\s*env\('RANKS_ENFORCE_CORE',\s*true\)/", $src);
        $this->assertMatchesRegularExpression("/'allow_manual_override'\s*=>\s*\(bool\)\s*env\('RANKS_ALLOW_MANUAL_OVERRIDE',\s*false\)/", $src);
    }

    public function test_with_no_criteria_at_all_nobody_is_ranked_however_much_they_have_invested(): void
    {
        $this->assertSame(0, RankCriterion::count());
        $inv = $this->investorWith(60000, 12);

        app(RankEvaluationService::class)->evaluateUser($inv->id);

        $this->assertNull($this->freshRank($inv), 'no approved criteria => no rank');
    }

    public function test_loaded_proposed_drafts_never_rank_anyone_until_they_are_activated(): void
    {
        $this->artisan('ranks:load-proposed')->assertSuccessful();       // inactive drafts only
        $this->assertSame(0, RankCriterion::where('is_active', true)->count());
        $inv = $this->investorWith(60000, 12);

        app(RankEvaluationService::class)->evaluateUser($inv->id);

        $this->assertNull($this->freshRank($inv), 'a draft is a proposal, not a rule');
    }
}
