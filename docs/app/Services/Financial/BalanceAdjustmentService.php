<?php
// LOCATION: app/Services/Financial/BalanceAdjustmentService.php
//
// The ONE place that performs admin / financial balance adjustments (audit F-03). Replaces three divergent copies that did a read-modify-write
// of an absolute balance from a stale model. Guarantees, per account: transaction + SELECT … FOR UPDATE on the users row, balance re-read AFTER
// the lock, exact-decimal arithmetic (App\Support\Decimal – no float), relative SQL write (balance = balance ± amount, never an absolute value
// from a stale copy), a BalanceAdjustment row (admin, reason, before, after, timestamp) and a FinancialAuditLog row in the SAME transaction.
// Bulk runs lock all affected rows in ascending id order (deterministic, deadlock-resistant) and are atomic across the accounts they change;
// accounts that cannot be changed (e.g. a deduction larger than the balance) are skipped and REPORTED, never silently.

namespace App\Services\Financial;

use App\Models\BalanceAdjustment;
use App\Models\FinancialApprovalRequest;
use App\Models\User;
use App\Services\FinancialAuditService;
use App\Support\Decimal;
use App\Support\StaffAccountGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BalanceAdjustmentService
{
    public function __construct(protected FinancialAuditService $audit) {}

    /** Does this single adjustment need a second approver under the configured rules? (single-amount test only; see requiresApproval()) */
    public function needsApproval(string $type, ?string $amount): bool
    {
        if (!config('financial_controls.dual_approval_enabled', true)) return false;
        if ($type === 'reset') return (bool) config('financial_controls.require_approval.balance_reset', true);
        if (!in_array($type, ['add', 'deduct'], true)) return false;
        $threshold = config('financial_controls.balance_threshold_usd');
        if ($threshold === null || trim((string) $threshold) === '') return false;
        return Decimal::gte(Decimal::money($amount), Decimal::money($threshold));
    }

    /**
     * R8 / F-14 threshold-splitting control. The cumulative limit applies to everything one requester has adjusted inside the rolling window
     * (executed single-actor adjustments + still-open approval requests). Management has NOT approved a cumulative figure: when
     * financial_controls.balance_cumulative_threshold_usd is empty, the existing per-adjustment threshold is reused as the cumulative limit
     * (conservative, invents no new number). If both are empty the control is off. See docs/REMAINING_MANAGEMENT_DECISIONS.md #1.
     */
    public function cumulativeLimit(): ?string
    {
        foreach (['balance_cumulative_threshold_usd', 'balance_threshold_usd'] as $k) {
            $v = config("financial_controls.{$k}");
            if ($v !== null && trim((string) $v) !== '') return Decimal::money($v);
        }
        return null;
    }

    public function cumulativeTotal(User $actor): string
    {
        $since = now()->subHours(max(1, (int) config('financial_controls.balance_cumulative_window_hours', 24)));
        $done = BalanceAdjustment::where('admin_id', $actor->id)->whereIn('type', ['add', 'deduct'])->whereNull('approval_request_id')
            ->where('created_at', '>=', $since)->pluck('amount')->map(fn ($a) => Decimal::money($a))->all();
        $open = FinancialApprovalRequest::where('requested_by', $actor->id)->where('operation', 'balance_adjust')
            ->where('status', FinancialApprovalRequest::PENDING)->whereNotNull('amount_usd')->pluck('amount_usd')->map(fn ($a) => Decimal::money($a))->all();
        return Decimal::sum(array_merge($done, $open));
    }

    /** Full policy decision for one add/deduct/reset: single threshold OR cumulative window total. */
    public function requiresApproval(string $type, ?string $amount, User $actor): bool
    {
        if ($this->needsApproval($type, $amount)) return true;
        if (!config('financial_controls.dual_approval_enabled', true) || !in_array($type, ['add', 'deduct'], true)) return false;
        $limit = $this->cumulativeLimit();
        if ($limit === null) return false;
        return Decimal::gte(Decimal::add($this->cumulativeTotal($actor), Decimal::money($amount)), $limit);
    }

    /**
     * THE entry point for HTTP adjustments (Admin + Financial). In ONE transaction it: serialises the requester (so parallel sub-threshold
     * requests cannot each look small), refuses self-adjustment, locks the target and validates a deduction against the CURRENT balance
     * BEFORE any approval request exists (R3 — an impossible deduction is a 422, never a queued request), then either submits for second
     * approval (202, nothing moved) or applies immediately. Nothing is debited at submission.
     *
     * @return array{mode:string, request?:FinancialApprovalRequest, adjustment?:BalanceAdjustment, user?:User, duplicate:bool}
     * @throws ValidationException
     */
    public function submitOrApply(User $actor, int $userId, string $type, mixed $amount, string $reason, ?string $idemKey, bool $investorOnly): array
    {
        if (!in_array($type, ['add', 'deduct', 'freeze', 'unfreeze', 'reset'], true)) {
            throw ValidationException::withMessages(['type' => ['Unknown adjustment type.']]);
        }
        $this->assertNotSelf($actor, $userId);

        return DB::transaction(function () use ($actor, $userId, $type, $amount, $reason, $idemKey, $investorOnly) {
            User::whereKey($actor->id)->lockForUpdate()->first();                       // serialise this requester's adjustments (cumulative check)

            $q = User::whereKey($userId);
            if ($investorOnly) $q->where('role', 'investor');
            $target = $q->lockForUpdate()->first();                                      // lock the account the money would leave
            if (!$target) throw ValidationException::withMessages(['user' => ['Investor not found.']]);

            $amt = in_array($type, ['add', 'deduct'], true) ? Decimal::money($amount) : '0.00';
            if (in_array($type, ['add', 'deduct'], true) && !Decimal::gt($amt, '0')) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }
            if ($type === 'deduct' && Decimal::gt($amt, Decimal::money($target->balance))) {
                throw ValidationException::withMessages(['amount' => ['Cannot deduct more than the current balance.']]);   // R3: rejected BEFORE queuing
            }
            if ($type === 'unfreeze' && StaffAccountGuard::normalisedStatus($target) !== 'frozen') {
                throw ValidationException::withMessages(['type' => ['Only a frozen account can be unfrozen. Use activate to reinstate a suspended or deactivated account.']]);
            }

            if ($this->requiresApproval($type, $amt, $actor)) {
                $payload = ['user_id' => $target->id, 'type' => $type, 'amount' => $amt, 'reason' => $reason, 'investor_only' => $investorOnly];
                $o = app(FinancialApprovalService::class)->submitDetailed('balance_adjust', $payload, $actor, $idemKey);
                return ['mode' => 'pending', 'request' => $o['request'], 'duplicate' => $o['duplicate']];
            }

            $o = $this->adjust($target->id, $type, $amt, $reason, $actor, $idemKey, null, null, $investorOnly);
            return ['mode' => 'applied'] + $o;
        });
    }

    /** F-14: nobody adjusts their own account, directly or through an approval they requested. */
    public function assertNotSelf(User $actor, int $targetUserId): void
    {
        if ((int) $actor->id === $targetUserId) {
            throw ValidationException::withMessages(['user' => ['You cannot adjust your own account.']]);
        }
    }

    /**
     * Apply ONE adjustment to ONE account. Idempotent per ($userId, $idemKey).
     *
     * @return array{adjustment: BalanceAdjustment, user: User, duplicate: bool}
     * @throws ValidationException (422) on insufficient balance / invalid input
     */
    public function adjust(int $userId, string $type, mixed $amount, string $reason, User $actor, ?string $idemKey = null,
                           ?int $approvalId = null, ?int $approvedBy = null, bool $investorOnly = false): array
    {
        if (!in_array($type, ['add', 'deduct', 'freeze', 'unfreeze', 'reset'], true)) {
            throw ValidationException::withMessages(['type' => ['Unknown adjustment type.']]);
        }

        $this->assertNotSelf($actor, $userId);

        return DB::transaction(function () use ($userId, $type, $amount, $reason, $actor, $idemKey, $approvalId, $approvedBy, $investorOnly) {
            $q = User::whereKey($userId);
            if ($investorOnly) $q->where('role', 'investor');
            $u = $q->lockForUpdate()->first();                       // 1. lock …
            if (!$u) throw ValidationException::withMessages(['user' => ['Investor not found.']]);

            $dedupe = $idemKey ? "{$userId}:{$idemKey}" : null;
            if ($dedupe && ($prev = BalanceAdjustment::where('idempotency_key', $dedupe)->first())) {   // 2. duplicate submission → return the original
                return ['adjustment' => $prev, 'user' => $u, 'duplicate' => true];
            }

            $before = Decimal::money($u->balance);                  // 3. … then read the LATEST balance
            $amt = in_array($type, ['add', 'deduct'], true) ? Decimal::money($amount) : '0.00';
            if (in_array($type, ['add', 'deduct'], true) && !Decimal::gt($amt, '0')) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }

            $after = $before;
            switch ($type) {
                case 'add':
                    User::whereKey($u->id)->increment('balance', $amt);
                    $after = Decimal::money(Decimal::add($before, $amt));
                    break;
                case 'deduct':
                    if (Decimal::gt($amt, $before)) {
                        throw ValidationException::withMessages(['amount' => ['Cannot deduct more than the current balance.']]);
                    }
                    $n = User::whereKey($u->id)->where('balance', '>=', $amt)->decrement('balance', $amt);
                    if ($n !== 1) throw ValidationException::withMessages(['amount' => ['Cannot deduct more than the current balance.']]);
                    $after = Decimal::money(Decimal::sub($before, $amt));
                    break;
                case 'reset':
                    User::whereKey($u->id)->update(['balance' => '0.00']);
                    $after = '0.00';
                    break;
                case 'freeze':
                    // Only an ACTIVE account is frozen; freezing must not downgrade/alter a suspended or deactivated one.
                    if (StaffAccountGuard::normalisedStatus($u) !== 'active') {
                        throw ValidationException::withMessages(['type' => ['Only an active account can be frozen.']]);
                    }
                    $u->forceFill(['status' => 'frozen'])->save();
                    break;
                case 'unfreeze':
                    // R9: unfreezing must never reactivate a suspended / deactivated / inactive account — only a frozen one returns to active.
                    if (StaffAccountGuard::normalisedStatus($u) !== 'frozen') {
                        throw ValidationException::withMessages(['type' => ['Only a frozen account can be unfrozen. Use activate to reinstate a suspended or deactivated account.']]);
                    }
                    $u->forceFill(['status' => 'active'])->save();
                    break;
            }

            $adj = BalanceAdjustment::create([
                'user_id' => $u->id, 'admin_id' => $actor->id, 'type' => $type, 'amount' => $amt,
                'balance_before' => $before, 'balance_after' => $after, 'reason' => $reason,
                'idempotency_key' => $dedupe, 'approval_request_id' => $approvalId, 'approved_by' => $approvedBy,
            ]);
            $this->audit->record($actor, "wallet.{$type}", 'balance_adjustment', $adj->id, $u->id,
                ['balance' => $before], ['balance' => $after, 'amount' => $amt, 'approval_request_id' => $approvalId, 'approved_by' => $approvedBy], $reason);

            return ['adjustment' => $adj, 'user' => $u->fresh(), 'duplicate' => false];
        });
    }

    /**
     * R7 (direct balance writers): a SIGNED wallet movement for system-derived results (e.g. a verified task profit/loss), through the same
     * lock → re-read → relative SQL → BalanceAdjustment + FinancialAuditLog path as every other adjustment. $idemKey makes it exactly-once at the
     * database level (unique balance_adjustments.idempotency_key). $allowNegative preserves the documented task rule that a verified LOSS may
     * take the wallet below zero (it records what already happened on funds the investor committed elsewhere); every other caller leaves it false.
     * MUST be called inside the caller's transaction so the movement commits or rolls back together with the record that caused it.
     *
     * @return array{adjustment: BalanceAdjustment, user: User, duplicate: bool}
     */
    public function applySigned(int $userId, string $signedAmount, string $reason, User $actor, string $idemKey, bool $allowNegative = false): array
    {
        $signed = Decimal::money($signedAmount);
        if (Decimal::eq($signed, '0')) throw ValidationException::withMessages(['amount' => ['Amount must not be zero.']]);
        $type = Decimal::isNegative($signed) ? 'deduct' : 'add';
        $amt = Decimal::abs($signed);

        return DB::transaction(function () use ($userId, $type, $amt, $reason, $actor, $idemKey, $allowNegative) {
            $u = User::whereKey($userId)->lockForUpdate()->first();
            if (!$u) throw ValidationException::withMessages(['user' => ['Investor not found.']]);
            $dedupe = "{$userId}:{$idemKey}";
            if ($prev = BalanceAdjustment::where('idempotency_key', $dedupe)->first()) return ['adjustment' => $prev, 'user' => $u, 'duplicate' => true];

            $before = Decimal::money($u->balance);
            if ($type === 'add') {
                User::whereKey($u->id)->increment('balance', $amt);
                $after = Decimal::money(Decimal::add($before, $amt));
            } else {
                if (!$allowNegative && Decimal::gt($amt, $before)) throw ValidationException::withMessages(['amount' => ['Cannot deduct more than the current balance.']]);
                User::whereKey($u->id)->decrement('balance', $amt);
                $after = Decimal::money(Decimal::sub($before, $amt));
            }
            $adj = BalanceAdjustment::create(['user_id' => $u->id, 'admin_id' => $actor->id, 'type' => $type, 'amount' => $amt,
                'balance_before' => $before, 'balance_after' => $after, 'reason' => $reason, 'idempotency_key' => $dedupe]);
            $this->audit->record($actor, "wallet.{$type}", 'balance_adjustment', $adj->id, $u->id, ['balance' => $before],
                ['balance' => $after, 'amount' => $amt, 'source' => 'system_result', 'idempotency_key' => $idemKey], $reason);
            return ['adjustment' => $adj, 'user' => $u->fresh(), 'duplicate' => false];
        });
    }

    /** Resolve the investor ids a bulk request targets (investors only), ascending. */
    protected function targetIds(array $p): array
    {
        $q = User::where('role', 'investor');
        if (($p['scope'] ?? '') === 'selected') {
            $q->whereIn('id', $p['user_ids'] ?? []);
        } elseif (($p['scope'] ?? '') === 'sector') {
            $sectorId = $p['sector_id'];
            $q->whereHas('investmentAccounts', fn ($a) => $a->where('status', 'active')
                ->whereHas('investmentPlan.sectorCategory', fn ($c) => $c->where('sector_id', $sectorId)));
        }
        return $q->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    protected function delta(array $p, string $before): string
    {
        return ($p['amount_type'] ?? 'fixed') === 'percentage'
            ? Decimal::percentOf($before, Decimal::fromInput($p['amount']))
            : Decimal::money($p['amount']);
    }

    /**
     * PREVIEW (no writes, no locks): exactly what a bulk run would do right now. Balances may change before execution; execution recomputes
     * under locks and reports any difference.
     * @return array{count:int, will_change:int, skipped:array<int,array{user_id:int,reason:string}>, total_delta:string, rows:array<int,array<string,string|int>>, over_cap:bool}
     */
    public function previewBulk(array $p): array
    {
        $ids = $this->targetIds($p);
        $rows = []; $skipped = []; $total = '0.00';
        foreach (User::whereIn('id', $ids)->orderBy('id')->get(['id', 'balance']) as $u) {
            $before = Decimal::money($u->balance);
            $d = $this->delta($p, $before);
            if (!Decimal::gt($d, '0')) { $skipped[] = ['user_id' => $u->id, 'reason' => 'zero_delta']; continue; }
            if ($p['type'] === 'deduct' && Decimal::gt($d, $before)) { $skipped[] = ['user_id' => $u->id, 'reason' => 'insufficient_balance']; continue; }
            $after = $p['type'] === 'add' ? Decimal::add($before, $d) : Decimal::sub($before, $d);
            $total = Decimal::add($total, $d);
            $rows[] = ['user_id' => $u->id, 'before' => $before, 'delta' => $d, 'after' => Decimal::money($after)];
        }
        return ['count' => count($ids), 'will_change' => count($rows), 'skipped' => $skipped, 'total_delta' => Decimal::money($total),
                'rows' => $rows, 'over_cap' => count($ids) > (int) config('financial_controls.bulk_max_accounts', 500)];
    }

    /**
     * Execute a bulk run: ONE transaction, rows locked in ascending id order, balances re-read under the lock, every change recorded.
     * @return array{applied:int, skipped:array<int,array{user_id:int,reason:string}>, total_delta:string}
     */
    public function applyBulk(array $p, User $actor, ?int $approvalId = null, ?int $approvedBy = null): array
    {
        $ids = $this->targetIds($p);
        if (count($ids) > (int) config('financial_controls.bulk_max_accounts', 500)) {
            throw ValidationException::withMessages(['scope' => ['This bulk operation affects more accounts than the configured cap (financial_controls.bulk_max_accounts).']]);
        }

        return DB::transaction(function () use ($ids, $p, $actor, $approvalId, $approvedBy) {
            $locked = User::whereIn('id', $ids)->where('role', 'investor')->orderBy('id')->lockForUpdate()->get();   // deterministic lock order
            $applied = 0; $skipped = []; $total = '0.00';
            foreach ($locked as $u) {
                $before = Decimal::money($u->balance);
                $d = $this->delta($p, $before);
                if (!Decimal::gt($d, '0')) { $skipped[] = ['user_id' => $u->id, 'reason' => 'zero_delta']; continue; }
                if ($p['type'] === 'deduct') {
                    if (Decimal::gt($d, $before)) { $skipped[] = ['user_id' => $u->id, 'reason' => 'insufficient_balance']; continue; }
                    User::whereKey($u->id)->where('balance', '>=', $d)->decrement('balance', $d);
                    $after = Decimal::money(Decimal::sub($before, $d));
                } else {
                    User::whereKey($u->id)->increment('balance', $d);
                    $after = Decimal::money(Decimal::add($before, $d));
                }
                $adj = BalanceAdjustment::create(['user_id' => $u->id, 'admin_id' => $actor->id, 'type' => $p['type'], 'amount' => $d,
                    'balance_before' => $before, 'balance_after' => $after, 'reason' => $p['reason'],
                    'approval_request_id' => $approvalId, 'approved_by' => $approvedBy]);
                $this->audit->record($actor, "wallet.bulk_{$p['type']}", 'balance_adjustment', $adj->id, $u->id,
                    ['balance' => $before], ['balance' => $after, 'amount' => $d, 'approval_request_id' => $approvalId, 'approved_by' => $approvedBy], $p['reason']);
                $total = Decimal::add($total, $d);
                $applied++;
            }
            return ['applied' => $applied, 'skipped' => $skipped, 'total_delta' => Decimal::money($total)];
        });
    }
}
