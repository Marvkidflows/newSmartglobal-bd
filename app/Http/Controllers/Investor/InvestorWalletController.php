<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\Withdrawal;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Investor wallet summary and transaction history — READ-ONLY, own records only.
 *
 * Every figure is read from the authoritative backend tables (users.balance and the deposit / withdrawal / investment / adjustment
 * records). The frontend never computes a balance. This controller writes nothing. Admin notes, staff names and internal adjustment
 * reasons are never returned. Pending and held requests are reported as "pending" and do NOT reduce the available balance
 * (a withdrawal is debited only when it is approved; see AdminWithdrawalController::approve).
 */
class InvestorWalletController extends Controller
{
    private function label(string $status): string
    {
        return match ($status) { 'hold' => 'pending', default => $status };
    }

    // GET /investor/wallet
    public function summary()
    {
        $uid = Auth::id();
        $u = Auth::user();
        $sum = fn ($q) => Decimal::money($q->sum('amount'));

        $activeInvested = $sum(InvestmentAccount::where('user_id', $uid)->where('status', 'active'));
        $completed = InvestmentAccount::where('user_id', $uid)->where('status', 'completed');
        $profitCredited = Decimal::money((clone $completed)->sum('expected_profit'));

        return response()->json(['wallet' => [
            'currency'            => 'USD',
            'available_balance'   => Decimal::money($u->balance ?? 0),
            'active_invested'     => $activeInvested,
            'active_investments'  => InvestmentAccount::where('user_id', $uid)->where('status', 'active')->count(),
            'completed_investments' => (clone $completed)->count(),
            'profit_credited'     => $profitCredited,
            'pending_deposits'    => $sum(Deposit::where('user_id', $uid)->whereIn('status', ['pending', 'hold'])),
            'pending_withdrawals' => $sum(Withdrawal::where('user_id', $uid)->whereIn('status', ['pending', 'hold'])),
            'notes' => [
                'Pending deposits are not yet in your available balance.',
                'A pending withdrawal does not reduce your available balance until it is approved.',
                'Profit is credited to your available balance when an investment completes.',
            ],
        ]]);
    }

    // GET /investor/transactions?type=&status=&page=&per_page=
    public function transactions(Request $request)
    {
        $uid = Auth::id();
        $per = max(5, min(50, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $rows = collect();

        foreach (Deposit::where('user_id', $uid)->latest('id')->limit(200)->get() as $d) {
            $rows->push(['type' => 'deposit', 'reference' => $d->transaction_reference ?: 'DEP-' . $d->id, 'direction' => 'credit', 'amount' => Decimal::money($d->amount),
                'status' => $this->label($d->status), 'fee' => null, 'net' => null, 'date' => optional($d->processed_at ?? $d->created_at)->toIso8601String(), 'detail' => $d->status === 'approved' ? 'Credited to available balance' : null]);
        }
        foreach (Withdrawal::where('user_id', $uid)->latest('id')->limit(200)->get() as $w) {
            $rows->push(['type' => 'withdrawal', 'reference' => $w->transaction_reference ?: 'WDR-' . $w->id, 'direction' => 'debit', 'amount' => Decimal::money($w->gross_amount ?? $w->amount),
                'status' => $this->label($w->status), 'fee' => $w->fee_amount !== null ? Decimal::money($w->fee_amount) : null, 'net' => $w->net_amount !== null ? Decimal::money($w->net_amount) : null,
                'date' => optional($w->processed_at ?? $w->created_at)->toIso8601String(), 'detail' => $w->status === 'approved' ? 'Debited from available balance' : null]);
        }
        foreach (InvestmentAccount::where('user_id', $uid)->latest('id')->limit(200)->get() as $i) {
            $rows->push(['type' => 'investment', 'reference' => 'INV-' . $i->id, 'direction' => 'debit', 'amount' => Decimal::money($i->amount), 'status' => $i->status,
                'fee' => null, 'net' => null, 'date' => optional($i->start_date ?? $i->created_at)->toIso8601String(), 'detail' => 'Matures ' . (optional($i->end_date)->toDateString() ?? 'n/a')]);
            if ($i->status === 'completed' && !Decimal::eq(Decimal::money($i->expected_profit ?? 0), '0')) {
                $rows->push(['type' => 'profit_credit', 'reference' => 'INV-' . $i->id . '-PROFIT', 'direction' => 'credit', 'amount' => Decimal::money($i->expected_profit), 'status' => 'completed',
                    'fee' => null, 'net' => null, 'date' => optional($i->updated_at)->toIso8601String(), 'detail' => 'Profit credited at completion']);
            }
        }
        // Authorised adjustments only. Rows created by an approved deposit/withdrawal are the same movement as the records above, so they are excluded to avoid double counting.
        foreach (BalanceAdjustment::where('user_id', $uid)->where('reason', 'not like', 'Deposit #%')->where('reason', 'not like', 'Withdrawal #%')->latest('id')->limit(200)->get() as $a) {
            $rows->push(['type' => 'adjustment', 'reference' => 'ADJ-' . $a->id, 'direction' => $a->type === 'add' ? 'credit' : 'debit', 'amount' => Decimal::money($a->amount), 'status' => 'completed',
                'fee' => null, 'net' => null, 'date' => optional($a->created_at)->toIso8601String(), 'detail' => 'Authorised account adjustment']);
        }

        if (in_array($request->query('type'), ['deposit', 'withdrawal', 'investment', 'profit_credit', 'adjustment'], true)) $rows = $rows->where('type', $request->query('type'));
        if ($s = $request->query('status')) $rows = $rows->where('status', $s);
        $rows = $rows->sortByDesc('date')->values();
        $total = $rows->count();

        return response()->json(['transactions' => $rows->slice(($page - 1) * $per, $per)->values(),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $per))]]);
    }
}
