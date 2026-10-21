<?php
// LOCATION: app/Console/Commands/LoadProposedRankCriteria.php
//
//   php artisan ranks:load-proposed                       create the six PROPOSED rules as INACTIVE drafts
//   php artisan ranks:load-proposed --activate            create AND activate them (re-evaluates investors)
//   php artisan ranks:load-proposed --admin=a@b.com       record this Admin as the author (default: first active Admin)
//
// DEVELOPMENT ONLY. Refuses to run unless the app environment is one of
// config('ranks_proposed.allowed_environments') (local, testing, development). It is never called by
// migrations or deployment. Production criteria are approved and entered by an Admin in the dashboard.
// Everything goes through RankCriteriaService, so it is validated, versioned and audited exactly like
// a dashboard change. Safe to re-run: rules that already exist are skipped.

namespace App\Console\Commands;

use App\Models\InvestorRank;
use App\Models\RankCriterion;
use App\Models\User;
use App\Services\Rank\RankCriteriaService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class LoadProposedRankCriteria extends Command
{
    protected $signature = 'ranks:load-proposed {--activate : Activate the rules (development only)} {--admin= : Email of the Admin recorded as author}';
    protected $description = 'DEV ONLY: load the PROPOSED (unapproved) rank criteria from config/ranks_proposed.php.';

    /** sqlite (incl. in-memory) or an explicitly allow-listed local/container host. */
    public static function isLocalDatabase(array $connection): bool
    {
        if (($connection['driver'] ?? null) === 'sqlite') return true;

        return in_array($connection['host'] ?? null, (array) config('ranks_proposed.allowed_db_hosts', []), true);
    }

    public function handle(RankCriteriaService $criteria): int
    {
        $allowed = (array) config('ranks_proposed.allowed_environments', []);
        if (!app()->environment($allowed)) {
            $this->error('Refused: this command only runs in ' . implode(', ', $allowed) . ' (current: ' . app()->environment() . ').');
            $this->line('Proposed criteria are NOT approved. Enter approved criteria via Admin → Investor Ranks → Rank Criteria.');
            return self::FAILURE;
        }

        if (!static::isLocalDatabase((array) config('database.connections.' . config('database.default'), []))) {
            $this->error('Refused: the default database connection is not a local development database.');
            $this->line('This guard prevents loading unapproved criteria into a shared or production database even when APP_ENV=local.');
            return self::FAILURE;
        }

        $admin = $this->option('admin')
            ? User::where('role', 'admin')->where('email', $this->option('admin'))->first()
            : User::where('role', 'admin')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->orderBy('id')->first();

        if (!$admin) {
            $this->error('No Admin user found to record as the author. Create one or pass --admin=email.');
            return self::FAILURE;
        }

        $activate = (bool) $this->option('activate');
        $cur = config('ranks.ranking_currency', 'USD');
        $created = 0; $skipped = 0;

        foreach (config('ranks_proposed.ranks', []) as $key => $def) {   // Bronze → Elite
            $rank = InvestorRank::where('key', $key)->first();
            if (!$rank) { $this->warn("Rank [$key] not found — skipped."); continue; }

            $name = "Proposed {$rank->name} criteria (pending approval)";
            if (RankCriterion::where('investor_rank_id', $rank->id)->where('name', $name)->exists()) {
                $this->line("• {$rank->name}: already loaded — skipped");
                $skipped++;
                continue;
            }

            $band = $def['band_max'] === null
                ? number_format($def['min_amount']) . '+'
                : number_format($def['min_amount']) . '–' . number_format($def['band_max']);

            try {
                $out = $criteria->create([
                    'investor_rank_id' => $rank->id,
                    'name'             => $name,
                    'description'      => "PROPOSED by SSI Management, NOT approved. Band {$band} {$cur}; minimum {$def['min_cycles']} completed investment cycle(s).",
                    'logic'            => 'all',
                    'is_active'        => false,   // always created as a draft; activation (below) is a separate, ordered step
                    'reason'           => 'Loaded PROPOSED criteria in a development environment (pending Management approval)',
                    'conditions'       => [
                        ['field' => 'total_invested',            'operator' => '>=', 'value' => $def['min_amount'], 'group_no' => 1],
                        ['field' => 'completed_investment_count', 'operator' => '>=', 'value' => $def['min_cycles'], 'group_no' => 1],
                    ],
                ], $admin);
            } catch (ValidationException $e) {
                $this->error("• {$rank->name}: rejected — " . collect($e->errors())->flatten()->implode(' | '));
                return self::FAILURE;
            }

            $this->info("• {$rank->name}: {$band} {$cur}, ≥{$def['min_cycles']} cycles — inactive draft");
            $created++;
        }

        // Activate TOP-DOWN (Elite first). Each activation re-evaluates investors below that rank, so
        // an investor jumps straight to the highest rank they qualify for — one audit record and one
        // alert — instead of being walked up Bronze → Silver → Gold… with a record per step.
        $activated = 0;
        if ($activate) {
            foreach (array_reverse(array_keys(config('ranks_proposed.ranks', []))) as $key) {
                $rank = InvestorRank::where('key', $key)->first();
                $c = $rank ? RankCriterion::where('investor_rank_id', $rank->id)
                    ->where('name', "Proposed {$rank->name} criteria (pending approval)")->first() : null;
                if (!$c || $c->is_active) continue;

                try {
                    $criteria->activate($c, $admin, 'Activated PROPOSED criteria in a development environment');
                } catch (ValidationException $e) {
                    $this->error("• {$rank->name}: cannot activate — " . collect($e->errors())->flatten()->implode(' | '));
                    return self::FAILURE;
                }
                $this->info("• {$rank->name}: ACTIVATED");
                $activated++;
            }
        }

        $this->newLine();
        $this->info("Done: {$created} created, {$skipped} already present, {$activated} activated. Author recorded: {$admin->email}.");
        $this->warn('These thresholds are PROPOSED and unapproved. Do not use them in production without explicit Management approval.');
        if ($activate) $this->line('Activation queued re-evaluation runs; make sure a queue worker is running.');

        return self::SUCCESS;
    }
}
