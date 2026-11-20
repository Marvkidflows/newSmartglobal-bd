<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Services\Ledger\LedgerReaders;
use App\Support\Decimal;
use App\Support\FundingSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Active-investment reconciliation — READ-ONLY.
 *
 * For every position in scope (default ACTIVE; `scope=completed|all` adds COMPLETED positions — cancelled positions are never listed): how it was funded, principal, maturity, and (only where the principal ledger exists) principal-return status.
 * It credits, debits, migrates or edits nothing, and it recomputes no balance. A row is flagged only for a condition that is observable in the
 * records; whether a flagged position is "wrong" is a Finance/Management decision (D1), not something this report decides.
 */
class InvestmentReconciliationController extends Controller
{
    private const SCAN_CAP = 20000;

    // GET /financial/investment-reconciliation?exception=&source=&page=&per_page=
    public function index(Request $request)
    {
        Gate::authorize('financial.review-transactions');
        $v = $request->validate([
            'exception' => ['nullable', 'string', 'max:60'], 'source' => ['nullable', 'in:' . implode(',', [FundingSource::LEGACY_DEPOSIT, FundingSource::ALLOCATION, FundingSource::WALLET_LEGACY])],
            'scope' => ['nullable', 'in:active,completed,all'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $hasAlloc = LedgerReaders::has('investment_allocations');
        $hasPm    = LedgerReaders::has('principal_movements');
        $today = now()->toDateString();
        $scope = $v['scope'] ?? 'active';
        $statuses = ['active' => ['active'], 'completed' => ['completed'], 'all' => ['active', 'completed']][$scope];
        // Principal is only trackable where the principal ledger is in use (flag on, or movements already recorded). Otherwise "remaining" would
        // wrongly read as the full amount.
        $tracked = $hasPm && ((bool) config('financial.ledger_enabled') || DB::table('principal_movements')->exists());

        $rows = []; $scanned = 0; $truncated = false;
        $summary = ['by_source' => [], 'exceptions' => [], 'active_positions' => 0, 'active_principal' => '0.00', 'positions' => 0, 'principal' => '0.00'];
        foreach ([FundingSource::LEGACY_DEPOSIT, FundingSource::ALLOCATION, FundingSource::WALLET_LEGACY] as $s) $summary['by_source'][$s] = ['count' => 0, 'principal' => '0.00', 'label' => FundingSource::label($s)];

        InvestmentAccount::whereIn('status', $statuses)->with('user:id,name')->orderBy('id')->chunkById(500, function ($chunk) use (&$rows, &$scanned, &$truncated, &$summary, $hasAlloc, $hasPm, $today, $tracked) {
            if ($scanned >= self::SCAN_CAP) { $truncated = true; return false; }
            $ids = $chunk->pluck('id')->all();
            $alloc = $hasAlloc ? DB::table('investment_allocations')->whereIn('investment_id', $ids)->pluck('amount', 'investment_id')->all() : [];
            $deps = Deposit::whereIn('id', $chunk->pluck('source_deposit_id')->filter()->all())->get(['id', 'status', 'amount'])->keyBy('id');
            $reduced = $tracked ? LedgerReaders::reductionsDecimal($ids) : [];
            // duplicate funding records: several positions opened from one deposit (no unique index exists — decision D2), several allocation rows for one position
            $srcIds = $chunk->pluck('source_deposit_id')->filter()->unique()->all();
            $dupDeposits = $srcIds ? InvestmentAccount::whereIn('source_deposit_id', $srcIds)->select('source_deposit_id', DB::raw('COUNT(*) as c'))->groupBy('source_deposit_id')->havingRaw('COUNT(*) > 1')->pluck('c', 'source_deposit_id')->all() : [];
            $dupAlloc = $hasAlloc ? DB::table('investment_allocations')->whereIn('investment_id', $ids)->select('investment_id', DB::raw('COUNT(*) as c'))->groupBy('investment_id')->havingRaw('COUNT(*) > 1')->pluck('c', 'investment_id')->all() : [];

            foreach ($chunk as $i) {
                $scanned++;
                $src = FundingSource::classify($i, array_key_exists($i->id, $alloc));
                $amount = Decimal::money($i->amount);
                $ex = [];
                if ($src === FundingSource::LEGACY_DEPOSIT) $ex[] = ['key' => 'legacy_dual_overlap', 'severity' => 'review', 'text' => 'Opened by a deposit with a plan; the wallet was credited and not debited, so this principal overlaps wallet cash (F-02 / D1).'];
                if ($i->source_deposit_id) {
                    $d = $deps->get($i->source_deposit_id);
                    if (!$d) $ex[] = ['key' => 'source_deposit_missing', 'severity' => 'review', 'text' => 'The deposit that opened this position cannot be found.'];
                    else {
                        if ($d->status !== 'approved') $ex[] = ['key' => 'source_deposit_not_approved', 'severity' => 'review', 'text' => "Source deposit #{$d->id} is {$d->status}, not approved."];
                        if (abs((float) $d->amount - (float) $i->amount) > 0.004) $ex[] = ['key' => 'source_deposit_amount_differs', 'severity' => 'review', 'text' => "Source deposit #{$d->id} amount differs from the position principal."];
                    }
                }
                if (array_key_exists($i->id, $alloc) && abs((float) $alloc[$i->id] - (float) $i->amount) > 0.004) $ex[] = ['key' => 'allocation_amount_differs', 'severity' => 'review', 'text' => 'The linked allocation amount differs from the position principal.'];
                if ($i->source_deposit_id && isset($dupDeposits[$i->source_deposit_id])) $ex[] = ['key' => 'duplicate_source_deposit', 'severity' => 'review', 'text' => "{$dupDeposits[$i->source_deposit_id]} positions reference source deposit #{$i->source_deposit_id}."];
                if (isset($dupAlloc[$i->id])) $ex[] = ['key' => 'duplicate_allocation', 'severity' => 'review', 'text' => "{$dupAlloc[$i->id]} allocation rows are linked to this position."];
                if ($i->status === 'completed') {
                    $ex[] = $tracked && !Decimal::eq(Decimal::sub($amount, $reduced[$i->id] ?? '0.00'), '0')
                        ? ['key' => 'completed_principal_outstanding', 'severity' => 'review', 'text' => 'Completed; principal has not been fully returned or paid out according to the principal ledger.']
                        : (!$tracked ? ['key' => 'completed_principal_untracked', 'severity' => 'info', 'text' => 'Completed; whether the principal was returned cannot be determined from the records while the principal ledger is off.'] : null);
                    $ex = array_values(array_filter($ex));
                }
                if ($i->status === 'active' && !$i->end_date) $ex[] = ['key' => 'no_end_date', 'severity' => 'review', 'text' => 'No end date: never settled automatically.'];
                elseif ($i->status === 'active' && $i->end_date->toDateString() <= $today) $ex[] = ['key' => 'matured_unsettled', 'severity' => 'info', 'text' => 'Matured; still active. Scheduled settlement needs Finance approval (D-05); not an error.'];
                $red = $reduced[$i->id] ?? '0.00';
                if ($tracked && $i->status === 'active' && !Decimal::eq($red, '0')) $ex[] = ['key' => 'principal_reduced_while_active', 'severity' => 'review', 'text' => "Principal movements of {$red} are recorded against a position that is still active."];

                $summary['positions']++; $summary['principal'] = Decimal::add($summary['principal'], $amount);
                if ($i->status === 'active') { $summary['active_positions']++; $summary['active_principal'] = Decimal::add($summary['active_principal'], $amount); }
                $summary['by_source'][$src]['count']++;
                $summary['by_source'][$src]['principal'] = Decimal::add($summary['by_source'][$src]['principal'], $amount);
                foreach ($ex as $e) $summary['exceptions'][$e['key']] = ($summary['exceptions'][$e['key']] ?? 0) + 1;

                $rows[] = ['investment_id' => $i->id, 'investor_id' => $i->user_id, 'investor' => $i->user?->name, 'principal' => $amount, 'funding_source' => $src,
                    'source_deposit_id' => $i->source_deposit_id, 'start_date' => optional($i->start_date)->toDateString(), 'end_date' => optional($i->end_date)->toDateString(),
                    'matured' => $i->end_date ? $i->end_date->toDateString() <= $today : false,
                    'status' => $i->status, 'principal_return_status' => $tracked ? ('principal remaining ' . Decimal::sub($amount, $red) . ' of ' . $amount) : 'not trackable while the principal ledger is off',
                    'exceptions' => $ex];
            }
        });

        $filtered = collect($rows)->when($v['source'] ?? null, fn ($c, $s) => $c->where('funding_source', $s))
            ->when($v['exception'] ?? null, fn ($c, $k) => $c->filter(fn ($r) => collect($r['exceptions'])->contains('key', $k)))->values();
        $per = (int) ($v['per_page'] ?? 25); $page = (int) ($v['page'] ?? 1);

        return response()->json([
            'read_only' => true, 'scope' => $scope, 'scope_note' => $scope === 'active' ? 'Only ACTIVE positions are listed. COMPLETED positions (which may still have unreturned principal) and CANCELLED positions are NOT shown; choose scope = completed or all to include completed ones.' : ($scope === 'completed' ? 'Only COMPLETED positions are listed.' : 'ACTIVE and COMPLETED positions are listed; cancelled positions are not.'), 'principal_ledger_tracked' => $tracked, 'mode' => FundingSource::mode(), 'summary' => $summary, 'truncated' => $truncated,
            'positions' => $filtered->slice(($page - 1) * $per, $per)->values(),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $filtered->count(), 'last_page' => max(1, (int) ceil($filtered->count() / $per))],
            'note' => 'Read-only. Nothing is credited, debited, migrated or edited. A flag means "Finance should look", not "this is wrong"; the treatment of flagged accounts is decision D1.',
        ]);
    }
}
