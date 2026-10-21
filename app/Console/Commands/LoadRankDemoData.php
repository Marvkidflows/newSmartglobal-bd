<?php
// LOCATION: app/Console/Commands/LoadRankDemoData.php
//
//   php artisan ranks:demo-data                 create the demo investors, investments and valuations
//   php artisan ranks:demo-data --evaluate      also evaluate each demo investor now and print the rank they reach
//
// DEVELOPMENT / DEMONSTRATION ONLY — same guards as ranks:load-proposed: it refuses to run outside
// config('ranks_proposed.allowed_environments') and refuses unless the default database is local. It only ever
// creates clearly labelled demo investors (email ...@demo.invalid) and their records; it never touches an existing
// user, investment or setting, never enables the currency gate and never activates any criterion.
//
// ⚠ THE EXCHANGE RATES HERE ARE FIXTURES, not market rates. Every valuation is stored with the source
// "DEMO FIXTURE — test data, not a real exchange rate". They exist to show that thresholds are applied to the
// converted USD value and never to the raw amount. Safe to re-run: a demo investor that exists is skipped.

namespace App\Console\Commands;

use App\Models\InvestmentAccount;
use App\Models\InvestorRank;
use App\Models\User;
use App\Services\Rank\RankEvaluationService;
use App\Services\Rank\UsdValuationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoadRankDemoData extends Command
{
    public const SOURCE = 'DEMO FIXTURE — test data, not a real exchange rate';

    protected $signature = 'ranks:demo-data {--evaluate : Evaluate each demo investor now and print the rank reached} {--admin= : Email of the Admin recorded as verifier}';
    protected $description = 'DEV ONLY: create multi-currency demo investors (fixture rates) to demonstrate USD rank thresholds.';

    /**
     * name => [expected rank key|null, why, [[currency, amount, fixture rate, status], ...]]
     * Thresholds (USD, completed cycles): Bronze 100/1, Silver 1,000/2, Gold 5,000/3, Platinum 10,000/5, Diamond 20,000/8, Elite 50,000/12.
     */
    public static function scenarios(): array
    {
        $rep = fn (int $n, string $cur, float $amt, ?float $rate) => array_fill(0, $n, [$cur, $amt, $rate, 'completed']);

        return [
            'NGN raw amount is large, USD value is Silver' => ['silver',
                '3 × NGN 2,000,000 @ 0.00065 = $3,900 (raw 6,000,000 must NOT be compared with USD thresholds); 3 cycles → Silver, not Gold (needs $5,000)',
                $rep(3, 'NGN', 2000000, 0.00065)],
            'GBP exact Gold boundary' => ['gold',
                'GBP 2,000 + 1,000 + 1,000 @ 1.25 = exactly $5,000; 3 cycles → Gold',
                [['GBP', 2000, 1.25, 'completed'], ['GBP', 1000, 1.25, 'completed'], ['GBP', 1000, 1.25, 'completed']]],
            'EUR just below Gold' => ['silver',
                '3 × EUR 1,533.33 @ 1.08 = $4,967.99 (< $5,000); 3 cycles → Silver',
                $rep(3, 'EUR', 1533.33, 1.08)],
            'JPY smallest qualifying Bronze' => ['bronze',
                '1 × JPY 15,000 @ 0.0067 = $100.50 (≥ $100); 1 cycle → Bronze',
                [['JPY', 15000, 0.0067, 'completed']]],
            'Diamond but not Elite (11 cycles)' => ['diamond',
                '11 × USD 5,500 = $60,500 (≥ $50,000) but only 11 cycles (Elite needs 12) → Diamond',
                $rep(11, 'USD', 5500, null)],
            'Unverified currency counts as zero' => [null,
                '12 completed cycles of 100,000 with NO verified currency: counts as $0 until a person verifies it → no rank',
                array_fill(0, 12, [null, 100000, null, 'completed'])],
            'Elite from mixed currencies' => ['elite',
                '4 × USD 5,000 + 4 × EUR 4,000 @ 1.10 + 4 × GHS 50,000 @ 0.07 = $51,600; 12 cycles → Elite',
                array_merge($rep(4, 'USD', 5000, null), $rep(4, 'EUR', 4000, 1.10), $rep(4, 'GHS', 50000, 0.07))],
        ];
    }

    public function handle(UsdValuationService $valuations, RankEvaluationService $engine): int
    {
        $allowed = (array) config('ranks_proposed.allowed_environments', []);
        if (!app()->environment($allowed)) {
            $this->error('Refused: this command only runs in ' . implode(', ', $allowed) . ' (current: ' . app()->environment() . ').');
            return self::FAILURE;
        }
        if (!LoadProposedRankCriteria::isLocalDatabase((array) config('database.connections.' . config('database.default'), []))) {
            $this->error('Refused: the default database connection is not a local development database.');
            return self::FAILURE;
        }
        if (!InvestorRank::query()->exists()) {
            $this->error('The six ranks are not seeded. Run the migrations first.');
            return self::FAILURE;
        }

        $admin = $this->option('admin')
            ? User::where('role', 'admin')->where('email', $this->option('admin'))->first()
            : User::where('role', 'admin')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->orderBy('id')->first();
        if (!$admin) {
            $this->error('No Admin user found to record as the verifier. Create one or pass --admin=email.');
            return self::FAILURE;
        }

        $planId = DB::table('investment_plans')->where('name', 'DEMO plan (test data)')->value('id')
            ?? DB::table('investment_plans')->insertGetId(['name' => 'DEMO plan (test data)', 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);

        $made = []; $n = 0;
        foreach (static::scenarios() as $label => [$expected, $why, $lines]) {
            $n++;
            $email = "demo.rank.{$n}@demo.invalid";
            if ($existing = User::where('email', $email)->first()) {
                $this->line("• {$label}: already present — skipped");
                $made[] = [$existing, $label, $expected, $why];
                continue;
            }

            try {
                $user = DB::transaction(function () use ($label, $email, $lines, $planId, $valuations, $admin) {
                    $user = User::unguarded(fn () => User::create([
                        'name' => "DEMO · {$label}", 'email' => $email, 'password' => Hash::make(bin2hex(random_bytes(16))),
                        'role' => 'investor', 'status' => 'active', 'kyc_status' => 'approved', 'kyc_verified' => true,
                    ]));

                    foreach ($lines as $i => [$cur, $amount, $rate, $status]) {
                        // Distinct start dates so these are never mistaken for double-submits.
                        $inv = InvestmentAccount::create([
                            'user_id' => $user->id, 'investment_plan_id' => $planId, 'amount' => $amount, 'profit_percentage' => 0,
                            'expected_profit' => 0, 'status' => $status,
                            'start_date' => now()->subDays(60 + $i)->toDateString(), 'end_date' => now()->subDays(25 + $i)->toDateString(),
                        ]);
                        if ($cur === null) continue;      // deliberately left unverified

                        $valuations->record('investment', $inv->id, $cur, $rate, $cur === 'USD' ? null : self::SOURCE,
                            $cur === 'USD' ? null : now()->toDateString(), 'DEMO fixture: ' . ($cur === 'USD' ? 'stated USD' : "{$cur} at a fixture rate"), $admin);
                    }
                    return $user;
                });
            } catch (ValidationException $e) {
                $this->error("• {$label}: " . collect($e->errors())->flatten()->implode(' | '));
                return self::FAILURE;
            }

            $this->info("• {$label}: created ({$email})");
            $made[] = [$user, $label, $expected, $why];
        }

        $this->newLine();
        $this->warn('Exchange rates in this data are FIXTURES labelled "' . self::SOURCE . '". They are not market rates.');

        if (!$this->option('evaluate')) {
            $this->line('Next (development only, after Management has confirmed the currency handling in this environment):');
            $this->line('  1. set RANKS_CURRENCY_CONFIRMED=true in .env, then php artisan config:clear');
            $this->line('  2. php artisan ranks:load-proposed --activate');
            $this->line('  3. php artisan ranks:demo-data --evaluate');
            return self::SUCCESS;
        }

        if (!config('ranks.currency_confirmed')) {
            $this->error('Not evaluating: RANKS_CURRENCY_CONFIRMED is false, so money criteria cannot be active and the engine ignores them.');
            return self::FAILURE;
        }
        if (!$engine->activeCriteria()->count()) {
            $this->error('Not evaluating: no rank criteria are active. Run  php artisan ranks:load-proposed --activate  first.');
            return self::FAILURE;
        }

        $rows = []; $bad = 0;
        foreach ($made as [$user, $label, $expected, $why]) {
            $engine->evaluateUser($user->id, 'demo');
            $got = $user->fresh()->investorRank?->key;
            $ok  = $got === $expected;
            if (!$ok) $bad++;
            $rows[] = [$label, $expected ?? '— none —', $got ?? '— none —', $ok ? 'OK' : 'MISMATCH'];
        }
        $this->table(['Demo investor', 'Expected', 'Reached', ''], $rows);

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
