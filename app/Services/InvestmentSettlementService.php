<?php
// LOCATION: app/Services/InvestmentSettlementService.php
//
// The ONE place an investment is completed and its profit credited. Used by both the Admin
// "Complete" action and the scheduled maturity command, so the two can never double-pay.
//
// Safety properties (these did not exist before — complete() was a bare update + increment):
//   • runs in a transaction, with the investment row LOCKED and its status RE-READ under the lock,
//     so a stale in-memory copy (or a concurrent Admin click / scheduled run) cannot pay twice;
//   • only an `active` investment can be completed — completed and cancelled ones are refused;
//   • the profit credit uses the locked user row.
// It credits exactly what the previous code credited (expected_profit); it does not change the amount.

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvestmentSettlementService
{
    /**
     * @param  InvestmentAccount|int  $investment
     * @param  string                 $source  'admin' | 'schedule' (for the log only)
     * @return array{completed: bool, credited: float, reason: ?string}
     *         reason is null on success, else 'not_found' or 'status_<current status>'
     */
    public function complete(InvestmentAccount|int $investment, string $source, ?int $actorId = null): array
    {
        $id = $investment instanceof InvestmentAccount ? $investment->id : $investment;

        $result = DB::transaction(function () use ($id, $source, $actorId) {
            $locked = InvestmentAccount::query()->whereKey($id)->lockForUpdate()->first();

            if (!$locked) {
                return ['completed' => false, 'credited' => 0.0, 'reason' => 'not_found', 'user_id' => null];
            }
            if ($locked->status !== 'active') {
                return ['completed' => false, 'credited' => 0.0, 'reason' => 'status_' . $locked->status, 'user_id' => $locked->user_id];
            }

            // F-13: exact decimal (string) all the way to the SQL increment; no float is added to a balance.
            $profit = \App\Support\Decimal::money($locked->expected_profit ?? 0);

            // Fail closed (same rule as D-06 for withdrawals): if profit is due but the investor row is missing, do NOT mark the position completed,
            // because that would record a paid cycle that credited nobody. Nothing is written; the position stays active for a human to investigate.
            if (!\App\Support\Decimal::eq($profit, '0') && !User::query()->whereKey($locked->user_id)->lockForUpdate()->exists()) {
                return ['completed' => false, 'credited' => 0.0, 'reason' => 'missing_user', 'user_id' => $locked->user_id];
            }
            $locked->update(['status' => 'completed']);

            if (!\App\Support\Decimal::eq($profit, '0')) {
                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
                if ($user) {
                    $balanceBefore = \App\Support\Decimal::money($user->balance);
                    $user->increment('balance', $profit);
                    // Traceability while the ledger is OFF: an append-only audit row in the SAME transaction (nothing else records this credit).
                    // It is deliberately NOT a BalanceAdjustment, so the investor's wallet history is not double counted.
                    $actor = $actorId ? User::query()->find($actorId) : null;
                    \App\Models\FinancialAuditLog::create([
                        'reference' => 'FAL-' . strtoupper(\Illuminate\Support\Str::random(10)), 'actor_id' => $actor?->id,
                        'actor_name' => $actor ? ($actor->name ?? $actor->full_name ?? 'Staff') : 'System (maturity scheduler)', 'actor_role' => $actor?->role ?? 'system',
                        'department' => $actor ? ($actor->role === 'financial' ? 'financial' : 'admin') : 'system', 'action' => 'investment.profit_credited',
                        'entity_type' => 'investment_account', 'entity_id' => $locked->id, 'investor_id' => $user->id,
                        'previous_value' => ['status' => 'active', 'balance' => $balanceBefore],
                        'new_value' => ['status' => 'completed', 'profit_credited' => $profit, 'balance' => \App\Support\Decimal::add($balanceBefore, $profit), 'source' => $source],
                        'reason' => 'Profit credited at cycle completion; principal is not credited by this step.',
                    ]);
                    // Financial Team §1B: the profit credited at completion is its own classification. Principal is NOT credited here.
                    app(\App\Services\Ledger\WalletLedger::class)->record([
                        'entry_key' => "settle:{$locked->id}:profit", 'user_id' => $user->id, 'classification' => 'profit_credit', 'direction' => 'credit',
                        'amount' => $profit, 'investment_id' => $locked->id, 'balance_after' => \App\Support\Decimal::money($user->fresh()->balance), 'note' => 'Profit credited at cycle completion (principal untouched)',
                    ]);
                }
            }

            return ['completed' => true, 'credited' => (float) $profit, 'reason' => null, 'user_id' => $locked->user_id];
        });

        Log::info('investment settlement', [
            'investment_id' => $id, 'source' => $source, 'actor_id' => $actorId,
            'completed' => $result['completed'], 'credited' => $result['credited'], 'reason' => $result['reason'],
        ]);

        return ['completed' => $result['completed'], 'credited' => $result['credited'], 'reason' => $result['reason']];
    }
}
