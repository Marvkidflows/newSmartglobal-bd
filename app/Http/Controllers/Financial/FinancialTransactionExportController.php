<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustment;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\Withdrawal;
use App\Services\FinancialAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Finance transactions CSV export — READ-ONLY.
 *
 * Same four record sources as the Transactions screen (deposits, withdrawals, investments, adjustments). Columns are deliberately limited:
 * no e-mail, phone, payout account details, payment proof, admin/staff notes or adjustment reasons (free text can hold internal notes).
 * Gated by `financial.review-transactions`, throttled at the route, capped, spreadsheet-formula-safe, and every export is audited.
 * Which columns/roles Management wants beyond this is an open decision (register M-12).
 */
class FinancialTransactionExportController extends Controller
{
    private const DEFAULT_CAP = 5000;   // per source; override with config('financial.transactions_export_cap')
    private const HARD_CAP    = 50000;  // absolute ceiling: no configuration value can exceed it

    // GET /financial/transactions-export?type=&date_from=&date_to=
    public function export(Request $request, FinancialAuditService $audit)
    {
        Gate::authorize('financial.review-transactions');
        $v = $request->validate([
            'type'      => ['nullable', 'in:deposit,withdrawal,investment,adjustment'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $safe = fn ($x) => is_string($x) && $x !== '' && in_array($x[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $x : $x;
        $scope = fn ($q) => $q->when($v['date_from'] ?? null, fn ($qq, $d) => $qq->whereDate('created_at', '>=', $d))->when($v['date_to'] ?? null, fn ($qq, $d) => $qq->whereDate('created_at', '<=', $d));
        $want = fn (string $t) => empty($v['type']) || $v['type'] === $t;
        $cfg = config('financial.transactions_export_cap', self::DEFAULT_CAP);
        $cap = is_numeric($cfg) && (int) $cfg >= 1 ? min((int) $cfg, self::HARD_CAP) : self::DEFAULT_CAP;   // invalid/zero/negative => default; huge => hard ceiling
        $truncated = false;
        $rows = [];

        if ($want('deposit'))    foreach ($scope(Deposit::with('user:id,name'))->orderByDesc('id')->limit($cap + 1)->get()->tap(function ($c) use ($cap, &$truncated) { if ($c->count() > $cap) $truncated = true; })->take($cap) as $d)
            $rows[] = ['deposit', $d->transaction_reference ?: 'DEP-' . $d->id, $d->user_id, $d->user?->name, 'credit', (string) $d->amount, 'USD', $d->status, '', '', $d->created_at, $d->processed_at];
        if ($want('withdrawal')) foreach ($scope(Withdrawal::with('user:id,name'))->orderByDesc('id')->limit($cap + 1)->get()->tap(function ($c) use ($cap, &$truncated) { if ($c->count() > $cap) $truncated = true; })->take($cap) as $w)
            $rows[] = ['withdrawal', $w->transaction_reference ?: 'WDR-' . $w->id, $w->user_id, $w->user?->name, 'debit', (string) ($w->gross_amount ?? $w->amount), 'USD', $w->status,
                $w->fee_amount !== null ? (string) $w->fee_amount : '', $w->net_amount !== null ? (string) $w->net_amount : '', $w->created_at, $w->processed_at];
        if ($want('investment')) foreach ($scope(InvestmentAccount::with('user:id,name'))->orderByDesc('id')->limit($cap + 1)->get()->tap(function ($c) use ($cap, &$truncated) { if ($c->count() > $cap) $truncated = true; })->take($cap) as $i)
            $rows[] = ['investment', 'INV-' . $i->id, $i->user_id, $i->user?->name, 'principal', (string) $i->amount, 'USD', $i->status, '', '', $i->created_at, null];
        if ($want('adjustment')) foreach ($scope(BalanceAdjustment::with('user:id,name'))->orderByDesc('id')->limit($cap + 1)->get()->tap(function ($c) use ($cap, &$truncated) { if ($c->count() > $cap) $truncated = true; })->take($cap) as $a)
            $rows[] = ['adjustment', 'ADJ-' . $a->id, $a->user_id, $a->user?->name, $a->type, (string) $a->amount, 'USD', 'completed', '', '', $a->created_at, null];

        $audit->record($request->user(), 'transactions.exported', 'export', 0, null, null, ['rows' => count($rows), 'filters' => $v, 'cap_per_source' => $cap, 'truncated' => $truncated], 'Finance transactions CSV export');

        return response()->streamDownload(function () use ($rows, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['type', 'reference', 'investor_id', 'investor_name', 'direction', 'amount', 'currency', 'status', 'fee', 'net', 'created_at', 'processed_at']);
            foreach ($rows as $r) {
                $r[10] = $r[10] ? $r[10]->toIso8601String() : '';
                $r[11] = $r[11] ? $r[11]->toIso8601String() : '';
                fputcsv($out, array_map($safe, $r));
            }
            fclose($out);
        }, 'transactions-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv'] + ($truncated ? ['X-Export-Truncated' => 'true'] : []));
    }
}
