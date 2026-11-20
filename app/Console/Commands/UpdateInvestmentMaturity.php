<?php
namespace App\Console\Commands;

use App\Models\InvestmentAccount;
use App\Services\InvestmentSettlementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

//   php artisan investments:update-maturity --dry-run   REPORT ONLY: what would be completed and paid. Changes nothing.
//   php artisan investments:update-maturity             refresh countdowns; complete + pay matured investments
//
// ⚠ This command PAYS profit into investor balances. Because it was never actually scheduled
// (see RANK_MODULE.md §2.2), the FIRST real run will settle every investment that matured while it
// wasn't running, all at once. ALWAYS run --dry-run first and have the Financial team approve the total.
class UpdateInvestmentMaturity extends Command
{
    protected $signature = 'investments:update-maturity {--dry-run : Report only; change nothing and pay nothing} {--scheduled : Set by the scheduler. Refuses to pay unless Finance has recorded an approval reference and a per-run payout ceiling (D-05)}';
    protected $description = 'Update investment remaining days and complete + pay matured investments (use --dry-run first)';

    public function handle(InvestmentSettlementService $settlement): int
    {
        $dry = (bool) $this->option('dry-run');

        // D-05: the unattended (scheduled) run is fail-closed. It pays only when Finance has recorded BOTH an approval reference and the largest total a single
        // run may pay (the figure approved after reviewing --dry-run). Anything else, including the very first run that would settle the whole backlog, is
        // refused BEFORE a single investment is touched and logged for the operator. A human running the command by hand is unaffected.
        if ($this->option('scheduled') && !$dry && ($refusal = $this->scheduledRunRefusal()) !== null) {
            Log::warning('investments:update-maturity REFUSED (scheduled): ' . $refusal);
            $this->error('Refused: ' . $refusal . ' Nothing was changed and nobody was paid.');
            return self::FAILURE;
        }

        $refreshed = 0; $completed = 0; $skipped = 0; $noEndDate = 0; $paid = 0.0;
        $investors = []; $oldest = null; $wouldPay = 0.0;

        InvestmentAccount::query()->where('status', 'active')->orderBy('id')->chunkById(200, function ($chunk) use (
            $dry, $settlement, &$refreshed, &$completed, &$skipped, &$noEndDate, &$paid, &$investors, &$oldest, &$wouldPay
        ) {
            foreach ($chunk as $investment) {
                // An investment with no end date can never be shown to have matured. (live_remaining_days
                // reports 0 for it, which would otherwise be mistaken for "matured" and paid out.)
                if (!$investment->end_date) {
                    $noEndDate++;
                    continue;
                }

                $matured = Carbon::parse($investment->end_date)->startOfDay()->lte(Carbon::today());

                if ($dry) {
                    if ($matured) {
                        $completed++;
                        $wouldPay += (float) ($investment->expected_profit ?? 0);
                        $investors[$investment->user_id] = true;
                        $end = Carbon::parse($investment->end_date);
                        $oldest = $oldest && $oldest->lte($end) ? $oldest : $end;
                    }
                    continue;
                }

                $investment->syncRemainingDays();
                $refreshed++;

                if ($matured) {
                    // Locked, re-checked, idempotent — safe against a concurrent Admin "Complete".
                    $result = $settlement->complete($investment, 'schedule');
                    if ($result['completed']) { $completed++; $paid += $result['credited']; }
                    else { $skipped++; }
                }
            }
        });

        if ($dry) {
            $this->warn('DRY RUN — nothing was changed and nobody was paid.');
            $this->line("Matured active investments that WOULD be completed: {$completed}");
            $this->line('Total profit that WOULD be credited: ' . number_format($wouldPay, 2) . ' across ' . count($investors) . ' investor(s)');
            if ($oldest) $this->line('Oldest matured end date: ' . $oldest->toDateString() . ' (' . (int) $oldest->diffInDays(Carbon::today()) . ' days ago)');
            if ($noEndDate) $this->line("Active investments with NO end date (never paid automatically): {$noEndDate}");
            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            Log::info('investments:update-maturity scheduled run', ['approval_ref' => config('scheduling.investment_maturity_approval_ref'), 'refreshed' => $refreshed, 'completed' => $completed, 'credited' => round($paid, 2), 'skipped' => $skipped]);
        }
        $this->info("Investment maturity updated: {$refreshed} refreshed, {$completed} completed and paid out (" . number_format($paid, 2) . " credited)."
            . ($skipped ? " {$skipped} skipped (already settled elsewhere)." : '')
            . ($noEndDate ? " {$noEndDate} active investment(s) have no end date and were not paid." : ''));

        return self::SUCCESS;
    }

    /** Null when the scheduled run may proceed, otherwise the reason it must not. */
    private function scheduledRunRefusal(): ?string
    {
        $ref = trim((string) config('scheduling.investment_maturity_approval_ref'));
        $max = config('scheduling.investment_maturity_max_run_total');
        if ($ref === '' || $max === null || $max === '' || !is_numeric($max) || (float) $max <= 0) {
            return 'no Finance approval is recorded (set INVESTMENT_MATURITY_APPROVAL_REF and INVESTMENT_MATURITY_MAX_RUN_TOTAL after reviewing the --dry-run output).';
        }
        $wouldPay = (float) InvestmentAccount::query()->where('status', 'active')->whereNotNull('end_date')
            ->whereDate('end_date', '<=', Carbon::today()->toDateString())->sum('expected_profit');
        if ($wouldPay > (float) $max) {
            return 'this run would pay ' . number_format($wouldPay, 2) . ', which is above the approved per-run ceiling of ' . number_format((float) $max, 2) . '. Review --dry-run and have Finance approve a new ceiling.';
        }
        return null;
    }
}
