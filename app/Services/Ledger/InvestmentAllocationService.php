<?php
// LOCATION: app/Services/Ledger/InvestmentAllocationService.php
//
// Wallet → investment allocation (Financial Team §1A/§1B), used by the investor's "invest from wallet" action when the ledger is enabled.
// ONE controlled transaction under row locks: validate the available wallet cash, record the selection and the plan terms that applied,
// debit the wallet, create the position, and write the linked ledger legs. If anything fails nothing is written, so the same money can
// never be both available wallet cash and allocated principal. The position fields it creates are exactly those the legacy path creates.

namespace App\Services\Ledger;

use App\Models\InvestmentAccount;
use App\Models\InvestmentAllocation;
use App\Models\InvestmentPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentAllocationService
{
    public function __construct(protected WalletLedger $ledger) {}

    /** @return array{0: InvestmentAccount, 1: InvestmentAllocation|null, 2: float} [position, allocation record, plan return percent] */
    public function allocate(User $user, InvestmentPlan $plan, float $amount, ?User $actor = null): array
    {
        return DB::transaction(function () use ($user, $plan, $amount, $actor) {
            $u = User::whereKey($user->id)->lockForUpdate()->first();
            if ((float) $u->balance < $amount) {
                throw ValidationException::withMessages(['amount' => ['Insufficient balance. Please make a deposit first.']]);
            }
            if ($amount < (float) $plan->min_amount) throw ValidationException::withMessages(['amount' => ['Amount is below the minimum for this plan.']]);
            if ($plan->max_amount && $amount > (float) $plan->max_amount) throw ValidationException::withMessages(['amount' => ['Amount exceeds the maximum for this plan.']]);

            $durationDays   = $plan->duration_days ?? (($plan->duration_months ?? 1) * 30);
            $profitPercent  = $plan->profit_percentage ?? $plan->profit_percent ?? 0;
            $expectedProfit = $amount * ($profitPercent / 100);

            $position = InvestmentAccount::create([
                'user_id' => $u->id, 'investment_plan_id' => $plan->id, 'amount' => $amount, 'profit_percentage' => $profitPercent,
                'expected_profit' => $expectedProfit, 'total_return' => $amount + $expectedProfit, 'status' => 'active',
                'start_date' => now(), 'end_date' => now()->addDays($durationDays), 'remaining_days' => $durationDays,
            ]);

            $before = (float) $u->balance;
            $u->decrement('balance', $amount);
            $after = (float) $u->fresh()->balance;

            $group = $this->ledger->newGroup();
            $alloc = null;
            if ($this->ledger->enabled()) {
                $alloc = InvestmentAllocation::create([
                    'user_id' => $u->id, 'investment_plan_id' => $plan->id, 'investment_id' => $position->id, 'amount' => $amount, 'entry_group' => $group, 'status' => 'completed',
                    'plan_terms' => ['plan_name' => $plan->name, 'profit_percentage' => (float) $profitPercent, 'duration_days' => (int) $durationDays,
                                     'min_amount' => (float) $plan->min_amount, 'max_amount' => $plan->max_amount ? (float) $plan->max_amount : null, 'wallet_balance_before' => $before],
                    'created_at' => now(),
                ]);
                $actorId = ($actor ?? $u)->id; $actorName = ($actor ?? $u)->name ?? 'Investor';
                $this->ledger->record(['entry_group' => $group, 'entry_key' => "alloc:{$alloc->id}:debit", 'user_id' => $u->id, 'classification' => 'wallet_allocation_debit', 'direction' => 'debit',
                    'amount' => $amount, 'investment_id' => $position->id, 'allocation_id' => $alloc->id, 'balance_after' => $after, 'actor_id' => $actorId, 'actor_name' => $actorName,
                    'note' => 'Wallet cash allocated to a new investment position']);
                $this->ledger->record(['entry_group' => $group, 'entry_key' => "alloc:{$alloc->id}:principal", 'user_id' => $u->id, 'classification' => 'allocated_investment_principal', 'direction' => 'credit',
                    'amount' => $amount, 'investment_id' => $position->id, 'allocation_id' => $alloc->id, 'actor_id' => $actorId, 'actor_name' => $actorName,
                    'note' => 'Principal allocated to the position (linked to the wallet debit)']);
            }

            return [$position, $alloc, (float) $profitPercent];
        });
    }
}
