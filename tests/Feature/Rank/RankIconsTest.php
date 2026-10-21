<?php
// LOCATION: tests/Feature/Rank/RankIconsTest.php
// Every rank has its own badge icon (distinct by shape, not only colour), the colours/labels/levels
// are preserved, and the migration that fixed Bronze/Silver is safe to re-run.

namespace Tests\Feature\Rank;

use App\Models\InvestorRank;
use Illuminate\Support\Facades\DB;

class RankIconsTest extends RankTestCase
{
    public function test_all_six_ranks_resolve_to_distinct_supported_icons(): void
    {
        $ranks = InvestorRank::orderBy('level')->get();
        $this->assertCount(6, $ranks);

        $icons = $ranks->pluck('icon')->all();
        $this->assertCount(6, array_unique($icons), 'ranks share an icon: ' . implode(', ', $icons));
        foreach ($icons as $icon) {
            $this->assertContains($icon, InvestorRank::SUPPORTED_ICONS, "icon [$icon] has no frontend mapping");
        }
        $this->assertSame(
            ['bronze' => 'shield', 'silver' => 'shield-check', 'gold' => 'award', 'platinum' => 'star', 'diamond' => 'diamond', 'elite' => 'crown'],
            $ranks->pluck('icon', 'key')->all()
        );
    }

    public function test_colours_labels_and_levels_are_preserved(): void
    {
        $this->assertSame(
            ['bronze' => ['Bronze', 1, '#B0713A'], 'silver' => ['Silver', 2, '#8A97A8'], 'gold' => ['Gold', 3, '#C9A84C'],
             'platinum' => ['Platinum', 4, '#5B7C99'], 'diamond' => ['Diamond', 5, '#2FA7D9'], 'elite' => ['Elite', 6, '#6D28D9']],
            InvestorRank::orderBy('level')->get()->mapWithKeys(fn ($r) => [$r->key => [$r->name, $r->level, $r->color]])->all()
        );
    }

    public function test_badge_payload_carries_each_distinct_icon(): void
    {
        $this->assertCount(6, InvestorRank::all()->map(fn ($r) => $r->badge()['icon'])->unique());
    }

    public function test_the_frontend_badge_component_maps_every_supported_icon(): void
    {
        $file = base_path('../frontend/src/components/RankBadge.jsx');
        if (!is_file($file)) $this->markTestSkipped('Frontend source not alongside the backend in this checkout.');

        $src = file_get_contents($file);
        foreach (InvestorRank::SUPPORTED_ICONS as $icon) {
            $this->assertMatchesRegularExpression("/['\"]?" . preg_quote($icon, '/') . "['\"]?\s*:\s*Lu/", $src, "RankBadge.jsx does not map [$icon]");
        }
    }

    public function test_icon_migration_is_idempotent_and_never_overwrites_a_custom_icon(): void
    {
        $migration = require database_path('migrations/2026_10_19_100001_give_silver_rank_a_distinct_icon.php');

        $migration->up(); $migration->up();                                           // re-running changes nothing
        $this->assertSame('shield-check', InvestorRank::where('key', 'silver')->value('icon'));

        DB::table('investor_ranks')->where('key', 'silver')->update(['icon' => 'star']);   // deliberately customised
        $migration->up();
        $this->assertSame('star', InvestorRank::where('key', 'silver')->value('icon'));

        DB::table('investor_ranks')->where('key', 'silver')->update(['icon' => 'shield']); // an old install
        $migration->up();
        $this->assertSame('shield-check', InvestorRank::where('key', 'silver')->value('icon'));
    }
}
