<?php
// LOCATION: app/Services/Ledger/PrincipalService.php
//
// Position-level principal handling (Financial Team §2). It distinguishes, as separate recorded steps:
//   cycle completed   — the position's status (existing; changes nothing here)
//   principal released — principal has become eligible for settlement                       (informational movement)
//   returned to wallet — a linked principal-return transaction credits the wallet            (reduces the position)
//   paid out           — a verified EXTERNAL payout, no wallet movement                      (reduces the position)
//   withdrawal principal — the principal component of an approved, classified withdrawal    (reduces the position)
//   correction         — a signed, approved adjustment with evidence
//
// Rules enforced here: redemption is POSITION-SPECIFIC (the account-level "oldest first" fallback is NOT approved and NOT implemented);
// nothing exceeds the remaining principal; a pending/rejected withdrawal reduces nothing; cycle completion alone credits nothing;
// the same reduction can never be applied twice (unique source key, and a withdrawal-sourced reduction excludes a separate redemption
// for the same withdrawal). Everything runs under row locks, in one transaction, with an audit row.

namespace App\Services\Ledger;

use App\Models\BalanceAdjustment;
use App\Models\InvestmentAccount;
use App\Models\PrincipalMovement;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalComponent;
use App\Services\Rank\RankBenefitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrincipalService
{
    public function __construct(protected WalletLedger $ledger, protected RankBenefitService $audit) {}

    public function enabled(): bool
    {
        return (bool) config('financial.ledger_enabled') && LedgerReaders::has('principal_movements');
    }

    public function remaining(InvestmentAccount $i): float
    {
        $reduced = LedgerReaders::reductions([(int) $i->id])[(int) $i->id] ?? 0.0;
        return max(0.0, round((float) $i->amount - $reduced, 2));
    }

    // ── steps ───────────────────────────────────────────────────────────────────────────────────────────

    /** Principal has become eligible for settlement (informational; reduces nothing, credits nothing). Needs a completed position. */
    public function release(InvestmentAccount $position, string $note, User $actor): PrincipalMovement
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($position, $note, $actor) {
            $i = InvestmentAccount::whereKey($position->id)->lockForUpdate()->firstOrFail();
            if ($i->status !== 'completed') $this->fail('Principal can only be released for a position whose cycle has completed. Cycle completion alone does not release or pay out principal.');
            $key = "release:{$i->id}";
            if ($m = PrincipalMovement::where('source_key', $key)->first()) return $m;   // idempotent

            $m = $this->movement($i, 'release', $this->remaining($i), 'redemption', null, $key, $actor, null, $note);
            $this->audit->audit('principal', $m->id, 'principal_released', $actor, null, ['investment_id' => $i->id, 'amount' => $m->amount], $note);
            return $m;
        });
    }

    /** Principal credited back to the investor's wallet through a linked principal-return transaction; reduces the position. */
    public function returnToWallet(InvestmentAccount $position, float $amount, string $idempotencyKey, string $reference, string $evidence, User $actor, ?int $withdrawalId = null): PrincipalMovement
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($position, $amount, $idempotencyKey, $reference, $evidence, $actor, $withdrawalId) {
            $i = $this->lockRedeemable($position);
            $key = "redemption:{$idempotencyKey}:{$i->id}:return_to_wallet";
            if ($m = PrincipalMovement::where('source_key', $key)->first()) return $m;   // a retry returns the original, never a second credit

            $this->assertAmount($i, $amount);
            $this->assertReleasedIfCompleted($i);
            $this->assertEvidence($reference, $evidence);
            $this->assertNotAlreadyDeducted($withdrawalId, $i);

            $u = User::whereKey($i->user_id)->lockForUpdate()->first();
            $before = (float) $u->balance;
            $u->increment('balance', $amount);
            $after = (float) $u->fresh()->balance;
            BalanceAdjustment::create(['user_id' => $u->id, 'admin_id' => $actor->id, 'type' => 'add', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after,
                'reason' => "Principal returned from investment #{$i->id} ({$reference})"]);

            $group = $this->ledger->newGroup();
            $m = $this->movement($i, 'return_to_wallet', $amount, 'redemption', null, $key, $actor, $group, $evidence, $reference, $withdrawalId);
            $this->ledger->record(['entry_group' => $group, 'entry_key' => "pm:{$m->id}:wallet", 'user_id' => $u->id, 'classification' => 'principal_returned_to_wallet', 'direction' => 'credit',
                'amount' => $amount, 'investment_id' => $i->id, 'principal_movement_id' => $m->id, 'reference' => $reference, 'balance_after' => $after, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff']);
            $this->ledger->record(['entry_group' => $group, 'entry_key' => "pm:{$m->id}:position", 'user_id' => $u->id, 'classification' => 'allocated_investment_principal', 'direction' => 'debit',
                'amount' => $amount, 'investment_id' => $i->id, 'principal_movement_id' => $m->id, 'reference' => $reference, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff',
                'note' => 'Principal removed from the position (linked to the wallet credit)']);
            $this->audit->audit('principal', $m->id, 'principal_returned_to_wallet', $actor, ['remaining' => $this->remaining($i) + $amount], ['remaining' => $this->remaining($i), 'amount' => $amount], $evidence);
            return $m;
        });
    }

    /** A verified EXTERNAL payout of principal. No wallet movement. Reduces the position. */
    public function externalPayout(InvestmentAccount $position, float $amount, string $idempotencyKey, string $reference, string $evidence, User $actor, ?int $withdrawalId = null): PrincipalMovement
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($position, $amount, $idempotencyKey, $reference, $evidence, $actor, $withdrawalId) {
            $i = $this->lockRedeemable($position);
            $key = "redemption:{$idempotencyKey}:{$i->id}:external_payout";
            if ($m = PrincipalMovement::where('source_key', $key)->first()) return $m;

            $this->assertAmount($i, $amount);
            $this->assertReleasedIfCompleted($i);
            $this->assertEvidence($reference, $evidence);
            $this->assertNotAlreadyDeducted($withdrawalId, $i);

            $group = $this->ledger->newGroup();
            $m = $this->movement($i, 'external_payout', $amount, 'redemption', null, $key, $actor, $group, $evidence, $reference, $withdrawalId, true);
            $this->ledger->record(['entry_group' => $group, 'entry_key' => "pm:{$m->id}:payout", 'user_id' => $i->user_id, 'classification' => 'principal_paid_externally', 'direction' => 'debit',
                'amount' => $amount, 'investment_id' => $i->id, 'principal_movement_id' => $m->id, 'reference' => $reference, 'actor_id' => $actor->id, 'actor_name' => $actor->name ?? 'Staff',
                'note' => 'Verified external payout of principal (no wallet movement)']);
            $this->audit->audit('principal', $m->id, 'principal_paid_externally', $actor, ['remaining' => $this->remaining($i) + $amount], ['remaining' => $this->remaining($i), 'amount' => $amount], $evidence);
            return $m;
        });
    }

    /**
     * Called inside the withdrawal-approval transaction. Applies the PRINCIPAL components of a classified withdrawal to their positions.
     * A pending, rejected or on-hold withdrawal never reaches here. Profit components reduce nothing.
     */
    public function applyWithdrawal(Withdrawal $w, User $actor): int
    {
        if (!$this->enabled() || !LedgerReaders::has('withdrawal_components')) return 0;
        $applied = 0;
        foreach (WithdrawalComponent::where('withdrawal_id', $w->id)->where('component', 'principal')->get() as $c) {
            $i = InvestmentAccount::whereKey($c->investment_id)->lockForUpdate()->first();
            if (!$i || (int) $i->user_id !== (int) $w->user_id) continue;

            // Never double-deduct: a separate redemption already linked to this withdrawal excludes a withdrawal-sourced reduction.
            if (PrincipalMovement::where('withdrawal_id', $w->id)->where('investment_id', $i->id)->whereIn('kind', ['return_to_wallet', 'external_payout'])->exists()) {
                throw ValidationException::withMessages(['withdrawal' => ["This withdrawal's principal was already redeemed through a separate redemption record for investment #{$i->id}."]]);
            }
            $key = "withdrawal:{$w->id}:investment:{$i->id}:principal";
            if (PrincipalMovement::where('source_key', $key)->exists()) continue;           // idempotent

            $amount = (float) $c->amount;
            $this->assertAmount($i, $amount);
            $m = $this->movement($i, 'withdrawal_principal', $amount, 'withdrawal', (int) $w->id, $key, $actor, null, "Principal component of approved withdrawal #{$w->id}", "WD-{$w->id}", $w->id, true);
            $this->audit->audit('principal', $m->id, 'withdrawal_principal_applied', $actor, ['remaining' => $this->remaining($i) + $amount], ['remaining' => $this->remaining($i), 'amount' => $amount, 'withdrawal_id' => $w->id], null);
            $applied++;
        }
        return $applied;
    }

    /** A signed, approved adjustment (positive reduces principal, negative restores). Needs evidence and an approval reference. */
    public function correct(InvestmentAccount $position, float $signedAmount, string $idempotencyKey, string $approvalReference, string $evidence, User $actor): PrincipalMovement
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($position, $signedAmount, $idempotencyKey, $approvalReference, $evidence, $actor) {
            $i = InvestmentAccount::whereKey($position->id)->lockForUpdate()->firstOrFail();
            $key = "correction:{$idempotencyKey}:{$i->id}";
            if ($m = PrincipalMovement::where('source_key', $key)->first()) return $m;
            if (abs($signedAmount) < 0.01) $this->fail('A correction needs a non-zero amount.');
            if ($signedAmount > 0) $this->assertAmount($i, $signedAmount);
            elseif (abs($signedAmount) > round((float) $i->amount - $this->remaining($i), 2) + 0.0001) $this->fail('A correction cannot restore more principal than has been reduced.');
            if (mb_strlen(trim($approvalReference)) < 5 || mb_strlen(trim($evidence)) < 10) $this->fail('A correction needs an approval reference and the supporting evidence.');

            $m = $this->movement($i, 'correction', $signedAmount, 'correction', null, $key, $actor, null, $evidence, $approvalReference, verified: true);
            $this->audit->audit('principal', $m->id, 'principal_correction', $actor, null, ['investment_id' => $i->id, 'amount' => $signedAmount], $evidence);
            return $m;
        });
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────

    protected function movement(InvestmentAccount $i, string $kind, float $amount, string $sourceType, ?int $sourceId, string $key, User $actor, ?string $group, ?string $evidence, ?string $reference = null, ?int $withdrawalId = null, bool $verified = false): PrincipalMovement
    {
        return PrincipalMovement::create([
            'investment_id' => $i->id, 'user_id' => $i->user_id, 'kind' => $kind, 'status' => 'completed', 'amount' => $amount,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_key' => $key, 'withdrawal_id' => $withdrawalId, 'entry_group' => $group,
            'reference' => $reference, 'evidence' => $evidence, 'recorded_by' => $actor->id, 'recorded_by_name' => $actor->name ?? $actor->full_name ?? 'Staff',
            'verified_by' => $verified ? $actor->id : null, 'verified_at' => $verified ? now() : null, 'created_at' => now(),
        ]);
    }

    protected function lockRedeemable(InvestmentAccount $position): InvestmentAccount
    {
        $i = InvestmentAccount::whereKey($position->id)->lockForUpdate()->firstOrFail();
        if ($i->status === 'cancelled') $this->fail('A cancelled position has no principal to redeem.');
        return $i;
    }

    protected function assertAmount(InvestmentAccount $i, float $amount): void
    {
        if ($amount < 0.01) $this->fail('Enter the principal amount actually redeemed.');
        if ($amount > $this->remaining($i) + 0.0001) $this->fail('The amount exceeds the principal remaining in this position (' . number_format($this->remaining($i), 2) . ').');
    }

    /** For a completed position the principal must first have been RELEASED; completion alone is not proof of payout. */
    protected function assertReleasedIfCompleted(InvestmentAccount $i): void
    {
        if ($i->status === 'completed' && !PrincipalMovement::where('source_key', "release:{$i->id}")->exists()) {
            $this->fail('Record "principal released" for this completed position first: cycle completion alone does not release principal.');
        }
    }

    /** The reduction must not be applied twice through both a withdrawal record and a separate principal-adjustment record (Financial §2A). */
    protected function assertNotAlreadyDeducted(?int $withdrawalId, InvestmentAccount $i): void
    {
        if (!$withdrawalId) return;
        if (LedgerReaders::has('withdrawal_components') && WithdrawalComponent::where('withdrawal_id', $withdrawalId)->where('investment_id', $i->id)->where('component', 'principal')->exists()) {
            $this->fail("Withdrawal #{$withdrawalId} already carries a principal component for this position; recording a separate redemption would deduct the same principal twice.");
        }
        if (PrincipalMovement::where('withdrawal_id', $withdrawalId)->where('investment_id', $i->id)->whereIn('kind', ['withdrawal_principal', 'return_to_wallet', 'external_payout'])->exists()) {
            $this->fail("Principal for withdrawal #{$withdrawalId} was already deducted from this position.");
        }
    }

    protected function assertEvidence(string $reference, string $evidence): void
    {
        if (mb_strlen(trim($reference)) < 3 || mb_strlen(trim($evidence)) < 10) $this->fail('A transaction reference and supporting evidence (at least 10 characters) are required.');
    }

    protected function assertEnabled(): void
    {
        if (!$this->enabled()) abort(403, 'The principal ledger is not enabled in this environment.');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['principal' => [$message]]);
    }
}
