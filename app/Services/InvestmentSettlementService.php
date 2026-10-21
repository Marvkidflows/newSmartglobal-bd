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

        $result = DB::transaction(function () use ($id) {
            $locked = InvestmentAccount::query()->whereKey($id)->lockForUpdate()->first();

            if (!$locked) {
                return ['completed' => false, 'credited' => 0.0, 'reason' => 'not_found', 'user_id' => null];
            }
            if ($locked->status !== 'active') {
                return ['completed' => false, 'credited' => 0.0, 'reason' => 'status_' . $locked->status, 'user_id' => $locked->user_id];
            }

            $profit = (float) ($locked->expected_profit ?? 0);
            $locked->update(['status' => 'completed']);

            if ($profit != 0.0) {
                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
                if ($user) $user->increment('balance', $profit);
            }

            return ['completed' => true, 'credited' => $profit, 'reason' => null, 'user_id' => $locked->user_id];
        });

        Log::info('investment settlement', [
            'investment_id' => $id, 'source' => $source, 'actor_id' => $actorId,
            'completed' => $result['completed'], 'credited' => $result['credited'], 'reason' => $result['reason'],
        ]);

        return ['completed' => $result['completed'], 'credited' => $result['credited'], 'reason' => $result['reason']];
    }
}
