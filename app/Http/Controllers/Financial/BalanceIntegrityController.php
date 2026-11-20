<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Finance exception monitor — READ-ONLY.
 *
 * Reports only invariants that the existing approve/credit code guarantees, so a hit is a genuine exception to investigate, not an
 * accounting opinion. It does NOT recompute a "correct" balance (the platform has no single rule that does, and none is invented here),
 * writes nothing, and never changes a balance or a record.
 *
 *  1. negative_balance            users.balance < 0 (every debit is conditional on balance >= amount)
 *  2. deposit_no_credit           approved deposit with no "Deposit #id approved" adjustment (approved but never credited)
 *  3. deposit_multiple_credit     more than one "Deposit #id approved" adjustment (credited twice)
 *  4. withdrawal_no_debit         approved withdrawal with no "Withdrawal #id approved" adjustment (paid out, never debited)
 *  5. withdrawal_multiple_debit   more than one such adjustment (debited twice)
 *  6. multiple_pending_withdrawals more than one pending withdrawal for one investor (the submit rule allows one)
 *  7. approved_without_processor  approved deposit/withdrawal with no processed_by/processed_at
 */
class BalanceIntegrityController extends Controller
{
    private const LIMIT = 100;

    // GET /financial/balance-integrity
    public function index()
    {
        Gate::authorize('financial.review-transactions');
        $out = [];

        $out['negative_balance'] = User::where('role', 'investor')->where('balance', '<', 0)->limit(self::LIMIT)
            ->get(['id', 'name', 'balance'])->map(fn ($u) => ['investor_id' => $u->id, 'investor' => $u->name, 'balance' => (string) $u->balance])->all();

        $credits = BalanceAdjustment::where('reason', 'like', 'Deposit #% approved')->get(['reason'])->countBy(fn ($a) => $a->reason);
        $debits  = BalanceAdjustment::where('reason', 'like', 'Withdrawal #% approved')->get(['reason'])->countBy(fn ($a) => $a->reason);

        $approvedDeposits = Deposit::where('status', 'approved')->get(['id', 'user_id', 'amount', 'transaction_reference', 'processed_by', 'processed_at']);
        $approvedWithdrawals = Withdrawal::where('status', 'approved')->get(['id', 'user_id', 'amount', 'transaction_reference', 'processed_by', 'processed_at']);

        $row = fn ($m, $extra = []) => ['id' => $m->id, 'investor_id' => $m->user_id, 'amount' => (string) $m->amount, 'reference' => $m->transaction_reference] + $extra;
        $out['deposit_no_credit'] = $approvedDeposits->filter(fn ($d) => ($credits["Deposit #{$d->id} approved"] ?? 0) === 0)->take(self::LIMIT)->map(fn ($x) => $row($x))->values()->all();
        $out['deposit_multiple_credit'] = $approvedDeposits->filter(fn ($d) => ($credits["Deposit #{$d->id} approved"] ?? 0) > 1)->take(self::LIMIT)->map(fn ($d) => $row($d, ['count' => $credits["Deposit #{$d->id} approved"]]))->values()->all();
        $out['withdrawal_no_debit'] = $approvedWithdrawals->filter(fn ($w) => ($debits["Withdrawal #{$w->id} approved"] ?? 0) === 0)->take(self::LIMIT)->map(fn ($x) => $row($x))->values()->all();
        $out['withdrawal_multiple_debit'] = $approvedWithdrawals->filter(fn ($w) => ($debits["Withdrawal #{$w->id} approved"] ?? 0) > 1)->take(self::LIMIT)->map(fn ($w) => $row($w, ['count' => $debits["Withdrawal #{$w->id} approved"]]))->values()->all();
        $out['multiple_pending_withdrawals'] = Withdrawal::where('status', 'pending')->select('user_id', DB::raw('COUNT(*) as n'))->groupBy('user_id')->havingRaw('COUNT(*) > 1')->limit(self::LIMIT)->get()
            ->map(fn ($r) => ['investor_id' => $r->user_id, 'count' => (int) $r->n])->all();
        $out['approved_without_processor'] = $approvedDeposits->filter(fn ($d) => !$d->processed_by || !$d->processed_at)->map(fn ($d) => $row($d, ['kind' => 'deposit']))
            ->merge($approvedWithdrawals->filter(fn ($w) => !$w->processed_by || !$w->processed_at)->map(fn ($w) => $row($w, ['kind' => 'withdrawal'])))->take(self::LIMIT)->values()->all();

        $labels = [
            'negative_balance' => 'Negative investor balance', 'deposit_no_credit' => 'Approved deposit with no credit record',
            'deposit_multiple_credit' => 'Deposit credited more than once', 'withdrawal_no_debit' => 'Approved withdrawal with no debit record',
            'withdrawal_multiple_debit' => 'Withdrawal debited more than once', 'multiple_pending_withdrawals' => 'More than one pending withdrawal',
            'approved_without_processor' => 'Approved without a recorded processor',
        ];
        $checks = [];
        foreach ($labels as $k => $label) $checks[] = ['key' => $k, 'label' => $label, 'count' => count($out[$k]), 'rows' => $out[$k]];

        // INFORMATION ONLY (not counted as exceptions): normal operating states that Finance should still see.
        $today = now()->toDateString();
        $info = [
            ['key' => 'matured_awaiting_settlement', 'label' => 'Matured active investments awaiting settlement',
             'count' => InvestmentAccount::where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<=', $today)->count(),
             'note' => 'Not an error. Scheduled settlement is refused until Finance records an approval reference and a per-run ceiling (D-05); an Administrator can complete positions manually.'],
            ['key' => 'active_without_end_date', 'label' => 'Active investments with no end date',
             'count' => InvestmentAccount::where('status', 'active')->whereNull('end_date')->count(),
             'note' => 'These are never paid automatically.'],
            ['key' => 'legacy_dual_deposits', 'label' => 'Approved deposits that opened an investment while the wallet was also credited (legacy flow, F-02)',
             'count' => InvestmentAccount::whereNotNull('source_deposit_id')->where('status', 'active')->count(),
             'note' => 'Only meaningful while financial.separate_deposit_approval is OFF. Run `php artisan ssi:reconcile-legacy-deposits` (read-only) and see the Management decision on F-02. Not proof of loss.'],
        ];

        return response()->json([
            'read_only' => true, 'info' => $info, 'checks' => $checks, 'exceptions' => array_sum(array_column($checks, 'count')),
            'note' => 'Only invariants guaranteed by the existing approve/credit code are checked. Records approved before the adjustment trail existed can appear under "no credit/debit record"; investigate, do not edit. Nothing here changes a balance.',
        ]);
    }
}
