<?php
// LOCATION: app/Console/Commands/ReconcileLegacyDeposits.php
//
// php artisan ssi:reconcile-legacy-deposits [--out=path.csv] [--user=ID]
//
// F-02 RECONCILIATION REPORT — STRICTLY READ-ONLY. It only SELECTs. It never writes to the database, never changes a balance, an investment, a
// deposit or a ledger row, and it is not a correction. It lists every APPROVED deposit that (under the legacy flow, financial.separate_deposit_approval
// = false) both credited the full amount to wallet cash AND opened an active investment for the same amount, so that Management and the Financial
// Team can decide, account by account, what (if anything) to do. Any correction requires their explicit written approval and is out of scope here.
//
// Columns are facts + one derived figure (potential_overlap), which is a CEILING on money that may be represented twice and still withdrawable:
//   potential_overlap = min(current wallet balance, principal of this account's ACTIVE legacy-dual investments).
// It is not a proven loss: legitimate later deposits, profits and withdrawals also move the wallet. Reviewers must trace each account.

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Services\Ledger\LedgerReaders;
use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileLegacyDeposits extends Command
{
    protected $signature = 'ssi:reconcile-legacy-deposits {--out= : CSV path (default storage/app/reconciliation/legacy-deposits-<UTC timestamp>.csv)} {--user= : Limit to one user id} {--force : Allow overwriting an existing report file}';
    protected $description = 'READ-ONLY report of legacy deposits whose amount is both wallet cash and an active investment (F-02). Changes nothing.';

    public function handle(): int
    {
        $out = $this->option('out') ?: storage_path('app/reconciliation/legacy-deposits-' . now()->utc()->format('Ymd-His') . '.csv');
        $real = realpath(dirname($out)) ?: dirname($out);
        if (str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', public_path()))) {
            $this->error('Refusing to write a financial report inside the public web root: ' . $out);       // never expose it over HTTP
            return self::FAILURE;
        }
        if (file_exists($out) && !$this->option('force')) {
            $this->error("Refusing to overwrite the existing report {$out}. Choose another --out, or pass --force deliberately.");
            return self::FAILURE;
        }
        if (!is_dir(dirname($out)) && !@mkdir(dirname($out), 0750, true) && !is_dir(dirname($out))) {
            $this->error('Cannot create the output directory: ' . dirname($out));
            return self::FAILURE;
        }
        $fh = fopen($out, $this->option('force') ? 'w' : 'x');                                              // 'x' = exclusive create: cannot clobber a file created meanwhile
        if (!$fh) { $this->error("Cannot open {$out} for writing."); return self::FAILURE; }
        @chmod($out, 0600);                                                                                 // owner-only: it lists account ids, balances and positions

        fputcsv($fh, ['user_id', 'deposit_id', 'deposit_amount', 'deposit_processed_at', 'investment_id', 'investment_status', 'investment_amount',
            'funded_by_allocation_ledger', 'wallet_balance_now', 'active_legacy_dual_principal', 'potential_overlap', 'approved_withdrawals_total', 'classification']);

        $hasAlloc = LedgerReaders::has('investment_allocations');
        $q = Deposit::where('status', 'approved')->whereNotNull('investment_plan_id')->orderBy('id');
        if ($this->option('user')) $q->where('user_id', (int) $this->option('user'));

        $rows = 0; $flagged = 0; $overlapTotal = '0.00';
        $q->chunkById(500, function ($deposits) use ($fh, $hasAlloc, &$rows, &$flagged, &$overlapTotal) {
            foreach ($deposits as $d) {
                $inv = InvestmentAccount::where('source_deposit_id', $d->id)->first();
                if (!$inv) continue;                                           // separated flow: no investment opened by the approval
                $viaAlloc = $hasAlloc && DB::table('investment_allocations')->where('investment_id', $inv->id)->exists();
                if ($viaAlloc) continue;                                       // funded from wallet cash through the allocation service: not dual

                $balance = Decimal::money(DB::table('users')->where('id', $d->user_id)->value('balance'));
                $activeDual = Decimal::money(InvestmentAccount::where('user_id', $d->user_id)->where('status', 'active')->whereNotNull('source_deposit_id')->sum('amount'));
                $overlap = $inv->status === 'active' ? Decimal::min($balance, $activeDual) : '0.00';
                $wd = Decimal::money(DB::table('withdrawals')->where('user_id', $d->user_id)->where('status', 'approved')->sum('amount'));

                $class = $inv->status === 'active' ? 'LEGACY_DUAL_ACTIVE_REVIEW' : 'LEGACY_DUAL_CLOSED_HISTORICAL';
                if ($class === 'LEGACY_DUAL_ACTIVE_REVIEW') { $flagged++; }
                fputcsv($fh, [$d->user_id, $d->id, Decimal::money($d->amount), optional($d->processed_at)->toIso8601String(), $inv->id, $inv->status,
                    Decimal::money($inv->amount), 'no', $balance, $activeDual, $overlap, $wd, $class]);
                $rows++;
            }
        });
        fclose($fh);

        $this->info("Read-only reconciliation written to {$out}");
        $this->line("Rows: {$rows}; active legacy-dual positions needing review: {$flagged}. No data was modified.");
        return self::SUCCESS;
    }
}
