<?php
// LOCATION: app/Services/Financial/ProfitAdjustmentService.php
//
// Controlled editing of the profit terms of ACTIVE investments (audit F-04). Existing contractual returns must not change silently:
//  - preview() shows exactly which investments change, from what to what, with no writes;
//  - apply() runs in one transaction, locks the affected investment rows (ascending id), re-checks each is still active, uses exact decimals,
//    and writes a profit_adjustment_items row per investment with before AND after values, plus the aggregate profit_adjustments row
//    (admin, approver, reason, documented authorisation reference, timestamp);
//  - reverse() restores the recorded before-values (only where the investment is still active AND still holds the values this adjustment wrote,
//    so later legitimate changes are never overwritten) and reports any investment it could not restore.
// It is only ever invoked through FinancialApprovalService (second approver). Nothing in the rank engine, benefits code or schedulers calls it.

namespace App\Services\Financial;

use App\Models\InvestmentAccount;
use App\Models\ProfitAdjustment;
use App\Models\ProfitAdjustmentItem;
use App\Models\User;
use App\Services\FinancialAuditService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfitAdjustmentService
{
    public function __construct(protected FinancialAuditService $audit) {}

    protected function targetIds(array $p): array
    {
        $q = InvestmentAccount::where('status', 'active');
        switch ($p['scope']) {
            case 'sector':
                $sectorId = $p['sector_id'];
                $q->whereHas('investmentPlan.sectorCategory', fn ($c) => $c->where('sector_id', $sectorId));
                break;
            case 'plan':  $q->where('investment_plan_id', $p['investment_plan_id']); break;
            case 'users': $q->whereIn('user_id', $p['user_ids'] ?? []); break;
        }
        return $q->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array{before_pct:string, after_pct:string, before_expected:string, after_expected:string, before_total:string, after_total:string} */
    public function compute(InvestmentAccount $inv, string $change): array
    {
        $amount = Decimal::money($inv->amount);
        $beforePct = Decimal::money($inv->profit_percentage);
        $afterPct = Decimal::add($beforePct, $change);
        if (Decimal::isNegative($afterPct)) $afterPct = '0';
        $afterPct = Decimal::roundSigned($afterPct, 2);
        $afterExpected = Decimal::percentOf($amount, $afterPct);
        return [
            'before_pct' => $beforePct, 'after_pct' => $afterPct,
            'before_expected' => Decimal::money($inv->expected_profit), 'after_expected' => $afterExpected,
            'before_total' => Decimal::money($inv->total_return), 'after_total' => Decimal::add($amount, $afterExpected),
        ];
    }

    /** Read-only preview. */
    public function preview(array $p): array
    {
        $change = Decimal::roundSigned(Decimal::fromInput($p['percentage_change']), 2);
        $ids = $this->targetIds($p);
        $rows = [];
        foreach (InvestmentAccount::whereIn('id', $ids)->orderBy('id')->get() as $inv) {
            $c = $this->compute($inv, $change);
            if (Decimal::eq($c['before_pct'], $c['after_pct'])) continue;
            $rows[] = ['investment_id' => $inv->id, 'user_id' => $inv->user_id] + $c;
        }
        return ['scope' => $p['scope'], 'percentage_change' => $change, 'active_in_scope' => count($ids), 'will_change' => count($rows), 'rows' => $rows];
    }

    public function apply(array $p, User $requester, ?User $approver, ?int $approvalId): ProfitAdjustment
    {
        $change = Decimal::roundSigned(Decimal::fromInput($p['percentage_change']), 2);
        if (!Decimal::lte(Decimal::abs($change), '100')) {
            throw ValidationException::withMessages(['percentage_change' => ['Change must be between -100 and 100 points.']]);
        }
        if (trim((string) ($p['authorization_reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['authorization_reference' => ['A documented authorisation reference is required to change existing returns.']]);
        }

        return DB::transaction(function () use ($p, $change, $requester, $approver, $approvalId) {
            $ids = $this->targetIds($p);
            $adj = ProfitAdjustment::create([
                'admin_id' => $requester->id, 'scope' => $p['scope'], 'sector_id' => $p['sector_id'] ?? null,
                'investment_plan_id' => $p['investment_plan_id'] ?? null, 'percentage_change' => $change, 'affected_count' => 0,
                'reason' => $p['reason'], 'status' => 'applied', 'approval_request_id' => $approvalId, 'approved_by' => $approver?->id,
                'authorization_reference' => $p['authorization_reference'],
            ]);

            $count = 0;
            $locked = InvestmentAccount::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($locked as $inv) {
                if ($inv->status !== 'active') continue;             // re-check under the lock
                $c = $this->compute($inv, $change);
                if (Decimal::eq($c['before_pct'], $c['after_pct'])) continue;

                ProfitAdjustmentItem::create([
                    'profit_adjustment_id' => $adj->id, 'investment_id' => $inv->id, 'user_id' => $inv->user_id,
                    'before_profit_percentage' => $c['before_pct'], 'before_expected_profit' => $c['before_expected'], 'before_total_return' => $c['before_total'],
                    'after_profit_percentage' => $c['after_pct'], 'after_expected_profit' => $c['after_expected'], 'after_total_return' => $c['after_total'],
                    'created_at' => now(),
                ]);
                $inv->forceFill(['profit_percentage' => $c['after_pct'], 'expected_profit' => $c['after_expected'], 'total_return' => $c['after_total']])->save();
                $count++;
            }
            $adj->forceFill(['affected_count' => $count])->save();

            $this->audit->record($requester, 'profit.adjusted', 'profit_adjustment', $adj->id, null, null,
                ['scope' => $p['scope'], 'percentage_change' => $change, 'affected' => $count, 'approved_by' => $approver?->id,
                 'authorization_reference' => $p['authorization_reference']], $p['reason']);
            return $adj->fresh();
        });
    }

    /** @return array{restored:int, conflicts:array<int,array{investment_id:int,reason:string}>, status:string} */
    public function reverse(int $adjustmentId, string $reason, User $requester, ?User $approver, ?int $approvalId): array
    {
        return DB::transaction(function () use ($adjustmentId, $reason, $requester, $approver, $approvalId) {
            $adj = ProfitAdjustment::whereKey($adjustmentId)->lockForUpdate()->first();
            if (!$adj) throw ValidationException::withMessages(['adjustment' => ['Profit adjustment not found.']]);
            if ($adj->status === 'reversed') throw ValidationException::withMessages(['adjustment' => ['This adjustment has already been reversed.']]);

            $items = ProfitAdjustmentItem::where('profit_adjustment_id', $adj->id)->whereNull('reversed_at')->orderBy('investment_id')->get();
            $locked = InvestmentAccount::whereIn('id', $items->pluck('investment_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $restored = 0; $conflicts = [];
            foreach ($items as $it) {
                $inv = $locked->get($it->investment_id);
                if (!$inv || $inv->status !== 'active') { $conflicts[] = ['investment_id' => $it->investment_id, 'reason' => 'no_longer_active']; continue; }
                $stillAfter = Decimal::eq(Decimal::money($inv->profit_percentage), Decimal::money($it->after_profit_percentage))
                    && Decimal::eq(Decimal::money($inv->expected_profit), Decimal::money($it->after_expected_profit))
                    && Decimal::eq(Decimal::money($inv->total_return), Decimal::money($it->after_total_return));
                if (!$stillAfter) { $conflicts[] = ['investment_id' => $it->investment_id, 'reason' => 'changed_since_adjustment']; continue; }

                $inv->forceFill(['profit_percentage' => $it->before_profit_percentage, 'expected_profit' => $it->before_expected_profit, 'total_return' => $it->before_total_return])->save();
                $it->forceFill(['reversed_at' => now(), 'reversed_by' => $requester->id])->save();
                $restored++;
            }

            $remaining = ProfitAdjustmentItem::where('profit_adjustment_id', $adj->id)->whereNull('reversed_at')->count();
            $status = $remaining === 0 ? 'reversed' : 'partially_reversed';
            $adj->forceFill(['status' => $status, 'reversed_at' => now(), 'reversed_by' => $requester->id, 'reversal_reason' => $reason])->save();

            $this->audit->record($requester, 'profit.reversed', 'profit_adjustment', $adj->id, null, null,
                ['restored' => $restored, 'conflicts' => count($conflicts), 'status' => $status, 'approval_request_id' => $approvalId, 'approved_by' => $approver?->id], $reason);
            return ['restored' => $restored, 'conflicts' => $conflicts, 'status' => $status];
        });
    }
}
