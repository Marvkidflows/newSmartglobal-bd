<?php
// LOCATION: app/Services/Financial/InvestmentCorrectionService.php
//
// R7: the ONLY code path that changes an active investment's amount / profit percentage and recomputes expected_profit / total_return.
// Flow: HTTP → prepare() (locks the investment, validates, records the ORIGINAL and PROPOSED values, derives nothing yet) → second approver →
// execute() (re-locks, re-validates that the investment is still active/unpaid and STILL holds the original values, THEN recalculates with
// decimal arithmetic, writes, audits before/after, and the investor is notified only after the surrounding transaction has committed).
// Corrects the RECORD only — it never moves wallet cash (use the balance adjustment) and never changes principal ledger evidence.
// No arbitrary profit cap is invented: only the pre-existing technical bound (0–1000, validated by the controller) plus an OPTIONAL
// financial_controls.profit_percentage_cap that is empty until Management approves one (decision #2/#9).
namespace App\Services\Financial;

use App\Models\FinancialApprovalRequest;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Notifications\FinancialAlertNotification;
use App\Services\FinancialAuditService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentCorrectionService
{
    public function __construct(protected FinancialAuditService $audit) {}

    public function requiresApproval(): bool
    {
        return (bool) config('financial_controls.dual_approval_enabled', true) && (bool) config('financial_controls.require_approval.investment_correction', true);
    }

    public static function snapshot(InvestmentAccount $i): array
    {
        return ['amount' => Decimal::money($i->amount), 'profit_percentage' => Decimal::money($i->profit_percentage),
                'expected_profit' => Decimal::money($i->expected_profit), 'total_return' => Decimal::money($i->total_return)];
    }

    /** Validates and builds the request payload (original + proposed values). Writes NOTHING. */
    public function prepare(int $investmentId, ?string $amount, ?string $profitPct, string $reason, ?string $authorizationReference): array
    {
        if ($amount === null && $profitPct === null) {
            throw ValidationException::withMessages(['amount' => ['Provide a corrected amount and/or profit percentage.']]);
        }
        return DB::transaction(function () use ($investmentId, $amount, $profitPct, $reason, $authorizationReference) {
            [$inv, $owner] = $this->lockAndCheck($investmentId);
            $before = self::snapshot($inv);
            $newAmount = $amount !== null ? Decimal::money($amount) : $before['amount'];
            $newPct    = $profitPct !== null ? Decimal::money($profitPct) : $before['profit_percentage'];
            $this->assertValues($newAmount, $newPct);
            if (Decimal::eq($newAmount, $before['amount']) && Decimal::eq($newPct, $before['profit_percentage'])) {
                throw ValidationException::withMessages(['amount' => ['The corrected values are identical to the current record.']]);
            }
            return ['investment_id' => $inv->id, 'user_id' => $owner->id, 'before' => $before,
                    'proposed' => ['amount' => $newAmount, 'profit_percentage' => $newPct],
                    'reason' => $reason, 'authorization_reference' => $authorizationReference];
        });
    }

    /** @return array{investment_id:int, owner_id:int, before:array, after:array, audit_reference:?string} */
    public function execute(array $p, User $requester, ?User $approver, ?int $approvalId): array
    {
        return DB::transaction(function () use ($p, $requester, $approver, $approvalId) {
            [$inv, $owner] = $this->lockAndCheck((int) $p['investment_id']);          // re-validate state at execution time
            $current = self::snapshot($inv);
            foreach (['amount', 'profit_percentage'] as $k) {                         // the record must not have moved since the request was made
                if (!Decimal::eq($current[$k], Decimal::money($p['before'][$k]))) {
                    throw ValidationException::withMessages(['investment' => ["The investment's {$k} changed after this request was made. Cancel it and submit a new correction."]]);
                }
            }
            $newAmount = Decimal::money($p['proposed']['amount']);
            $newPct    = Decimal::money($p['proposed']['profit_percentage']);
            $this->assertValues($newAmount, $newPct);

            $expected = Decimal::percentOf($newAmount, $newPct);                      // derived ONLY now, after authorisation (decimal, half-up)
            $inv->forceFill(['amount' => $newAmount, 'profit_percentage' => $newPct, 'expected_profit' => $expected,
                             'total_return' => Decimal::add($newAmount, $expected)])->save();
            $after = self::snapshot($inv->fresh());

            $log = $this->audit->record($requester, 'investment.record_corrected', 'investment_account', $inv->id, $owner->id, $current,
                $after + ['approved_by' => $approver?->id, 'approval_request_id' => $approvalId, 'authorization_reference' => $p['authorization_reference'] ?? null],
                $p['reason']);

            return ['investment_id' => $inv->id, 'owner_id' => $owner->id, 'before' => $current, 'after' => $after, 'audit_reference' => $log?->reference];
        });
    }

    /** Called by the approval service only after a successful commit. */
    public function notifyAfterExecution(FinancialApprovalRequest $r, array $result): void
    {
        if ($r->operation !== 'investment_correction') return;
        $owner = User::find($result['owner_id'] ?? null);
        $owner?->notify(new FinancialAlertNotification('Investment record updated',
            'The Financial Team corrected one of your investment records after a review. Amount: $' . number_format((float) $result['before']['amount'], 2)
            . ' → $' . number_format((float) $result['after']['amount'], 2) . '.', 'investment_correction', ['investment_id' => $result['investment_id']]));
    }

    /** @return array{0:InvestmentAccount,1:User} */
    protected function lockAndCheck(int $investmentId): array
    {
        $inv = InvestmentAccount::whereKey($investmentId)->lockForUpdate()->first();   // same row the settlement / profit adjustment lock
        $owner = $inv ? User::find($inv->user_id) : null;
        if (!$inv || !$owner || $owner->role !== 'investor') throw ValidationException::withMessages(['investment' => ['Investment record not found.']]);
        if ($inv->status !== 'active' || $inv->is_paid) throw ValidationException::withMessages(['investment' => ['Only active, unpaid investment records can be corrected.']]);
        return [$inv, $owner];
    }

    protected function assertValues(string $amount, string $pct): void
    {
        if (!Decimal::gt($amount, '0')) throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        if (Decimal::isNegative($pct) || Decimal::gt($pct, '1000')) throw ValidationException::withMessages(['profit_percentage' => ['Profit percentage must be between 0 and 1000.']]);
        $cap = config('financial_controls.profit_percentage_cap');
        if ($cap !== null && trim((string) $cap) !== '' && Decimal::gt($pct, Decimal::money($cap))) {
            throw ValidationException::withMessages(['profit_percentage' => ["Profit percentage exceeds the approved cap of {$cap}%."]]);
        }
    }
}
