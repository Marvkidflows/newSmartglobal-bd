<?php
// LOCATION: app/Console/Commands/SyncRankBenefits.php
//
// php artisan ranks:sync-benefits [--dry-run]
//
// Safe, repeatable population of the rank benefits catalogue from the SSI framework. INSERT-ONLY: a benefit that
// already exists (matched by key) is never modified, so Management's edits survive every run. Touches no investor,
// rank assignment, criterion, balance or investment, and activates nothing (every default is the conservative
// status documented in BenefitDefaults). Safe in any environment, including production.

namespace App\Console\Commands;

use App\Models\InvestorRank;
use App\Models\RankBenefit;
use App\Services\Rank\BenefitDefaults;
use App\Services\Rank\RankBenefitService;
use Illuminate\Console\Command;

class SyncRankBenefits extends Command
{
    protected $signature = 'ranks:sync-benefits {--dry-run : Report what would be added without writing}';
    protected $description = 'Add any missing rank benefits from the SSI framework (insert-only, never overwrites).';

    public function handle(RankBenefitService $service): int
    {
        if (InvestorRank::count() < 6) {
            $this->error('The six investor ranks are not present. Run migrations first.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $existing = RankBenefit::pluck('key')->flip();
            $missing = collect(BenefitDefaults::all())->reject(fn ($d) => $existing->has($d['key']));
            $this->info($missing->count() . ' benefit(s) would be added, ' . $existing->count() . ' already exist.');
            foreach ($missing as $d) $this->line(" + {$d['key']}");
            return self::SUCCESS;
        }

        $r = $service->syncDefaults();
        $this->info("Added {$r['created']} benefit(s); {$r['skipped']} already present (left untouched).");
        if ($r['missing_ranks']) $this->warn('Skipped benefits for ranks not found: ' . implode(', ', $r['missing_ranks']));

        return self::SUCCESS;
    }
}
