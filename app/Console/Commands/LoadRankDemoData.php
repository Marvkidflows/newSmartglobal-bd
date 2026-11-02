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
     *
     * Two independent measures (Board §1):
     *   • CURRENT ELIGIBLE PRINCIPAL = verified USD principal in ACTIVE positions only (completed = $0).
     *   • COMPLETED CYCLES           = genuinely completed, verified investments (their history still counts after the principal has left).
     * Thresholds (USD principal / cycles): Bronze 100/1, Silver 1,000/2, Gold 5,000/3, Platinum 10,000/5, Diamond 20,000/8, Elite 50,000/12.
     * An 'active' line below is genuine current principal; a 'completed' line is history only. Nothing here is padded to force a result.
     */
    public static function scenarios(): array
    {
        $done = fn (int $n, string $cur, float $amt, ?float $rate) => array_fill(0, $n, [$cur, $amt, $rate, 'completed']);

        return [
            'History only: 12 cycles, no current principal' => [null,
                '12 completed USD 5,500 cycles meet every cycle minimum, but nothing is currently invested: current eligible principal is $0 → no rank (completed principal never counts)',
                $done(12, 'USD', 5500, null)],
            'Principal only: $60,000 active, no completed cycle' => [null,
                'USD 60,000 is currently invested and verified, but there is no completed cycle yet → no rank (amount never replaces cycles)',
                [['USD', 60000, null, 'active']]],
            'NGN raw amount is large, USD value is Silver' => ['silver',
                'History: 3 completed cycles. Current principal: NGN 2,000,000 @ 0.00065 = $1,300 (raw NGN is never compared with USD thresholds) → Silver; Gold needs $5,000',
                array_merge($done(3, 'NGN', 2000000, 0.00065), [['NGN', 2000000, 0.00065, 'active']])],
            'GBP exact Gold boundary' => ['gold',
                'History: 3 completed GBP 1,000 cycles. Current principal: GBP 4,000 @ 1.25 = exactly $5,000 → Gold',
                array_merge($done(3, 'GBP', 1000, 1.25), [['GBP', 4000, 1.25, 'active']])],
            'GBP just below Gold' => ['silver',
                'History: 3 completed cycles. Current principal: GBP 3,999.99 @ 1.25 = 4,999.9875 → $4,999.99 posted half-up, below $5,000 → Silver, not Gold',
                array_merge($done(3, 'GBP', 1000, 1.25), [['GBP', 3999.99, 1.25, 'active']])],
            'JPY smallest qualifying Bronze' => ['bronze',
                'History: 1 completed cycle. Current principal: JPY 15,000 @ 0.0067 = $100.50 (≥ $100) → Bronze',
                [['JPY', 15000, 0.0067, 'completed'], ['JPY', 15000, 0.0067, 'active']]],
            'Diamond but not Elite (11 cycles)' => ['diamond',
                'History: 11 completed USD 5,500 cycles (their principal is no longer counted). Current principal: USD 25,000 active → Diamond; Elite needs 12 cycles',
                array_merge($done(11, 'USD', 5500, null), [['USD', 25000, null, 'active']])],
            'Unverified currency counts as zero' => [null,
                '12 completed cycles of 100,000 with NO verified currency: unverified records add no cycle and no principal until a person verifies them → no rank',
                array_fill(0, 12, [null, 100000, null, 'completed'])],
            'Elite from mixed currencies' => ['elite',
                'History: 12 completed cycles (4 × USD, 4 × EUR @ 1.10, 4 × GHS @ 0.07). Current principal: USD 50,000 active → Elite',
                array_merge($done(4, 'USD', 5000, null), $done(4, 'EUR', 4000, 1.10), $done(4, 'GHS', 50000, 0.07), [['USD', 50000, null, 'active']])],
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
                            // A completed cycle is in the past; an ACTIVE position is genuinely running (not an expired one).
                            'start_date' => ($status === 'active' ? now()->subDays(5) : now()->subDays(60 + $i))->toDateString(),
                            'end_date'   => ($status === 'active' ? now()->addDays(25) : now()->subDays(25 + $i))->toDateString(),
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
            $this->line('  1. in the app: Investor Ranks → Currency policy — Financial reviews it, a DIFFERENT Administrator confirms it');
            $this->line('  2. php artisan ranks:load-proposed --activate');
            $this->line('  3. php artisan ranks:demo-data --evaluate');
            return self::SUCCESS;
        }

        if (!app(\App\Services\Rank\CurrencyPolicyService::class)->isConfirmed()) {
            $this->error('Not evaluating: the currency policy is not confirmed (Investor Ranks → Currency policy), so money criteria cannot be active and the engine ignores them.');
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
