<?php
// LOCATION: app/Services/Rank/RankStatusService.php
//
// Suspend / revoke / reinstate an investor's rank (spec §6: "award, correct, suspend, or revoke").
//   • The investor's rank row is kept; only users.rank_status changes. The badge is shown ONLY while 'active'.
//   • Needs an authorised account (gate checked here and at the route), a reason, and writes an append-only audit record.
//   • Never touches investments, deposits, withdrawals or balances.
//   • "Correct" = the existing manual assign / downgrade (RankChangeService), which already needs a reason + audit.

namespace App\Services\Rank;

use App\Jobs\EvaluateInvestorRankJob;
use App\Jobs\SendInvestorRankNotificationJob;
use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\RankCriterion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankStatusService
{
    public function __construct(
        protected RankAuditService $audit,
        protected RankEvaluationService $evaluation,
        protected RankEligibility $eligibility,
        protected RankChangeService $changes,
    ) {}

    public function suspend(User $investor, User $actor, string $reason): InvestorRankChange
    {
        return $this->transition($investor, $actor, $reason, 'suspended', ['active'], 'ranks.suspend', InvestorRankChange::SUSPENDED);
    }

    public function revoke(User $investor, User $actor, string $reason): InvestorRankChange
    {
        return $this->transition($investor, $actor, $reason, 'revoked', ['active', 'suspended'], 'ranks.revoke', InvestorRankChange::REVOKED);
    }

    public function reinstate(User $investor, User $actor, string $reason, bool $override = false, ?string $justification = null): InvestorRankChange
    {
        return $this->transition($investor, $actor, $reason, 'active', ['suspended', 'revoked'], 'ranks.assign', InvestorRankChange::REINSTATED, $override, $justification);
    }

    protected function transition(User $investor, User $actor, string $reason, string $to, array $from, string $ability, string $type, bool $override = false, ?string $justification = null): InvestorRankChange
    {
        Gate::forUser($actor)->authorize($ability);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);
        }

        $change = DB::transaction(function () use ($investor, $actor, $reason, $to, $from, $type, $override, $justification) {
            $user = User::query()->where('role', 'investor')->whereKey($investor->id)->lockForUpdate()->firstOrFail();

            if (!$user->investor_rank_id) {
                throw ValidationException::withMessages(['rank' => ['This investor has no rank to change.']]);
            }
            $status = $user->rank_status ?? 'active';
            if (!in_array($status, $from, true)) {
                throw ValidationException::withMessages(['rank' => ["The rank is currently {$status}; this action is not available."]]);
            }

            $rank = InvestorRank::findOrFail($user->investor_rank_id);
            $snapshot = ['status_from' => $status, 'status_to' => $to];

            if ($type === InvestorRankChange::REINSTATED) {
                // Reinstating is only free if the investor still qualifies; otherwise it is an exception to the rules.
                $criterion = RankCriterion::where('is_active', true)->where('investor_rank_id', $rank->id)->with(['rank', 'conditions'])->first();
                $prereq = $this->eligibility->check($user);
                $res = $criterion && $this->evaluation->usable($criterion) ? $this->evaluation->evaluateRuleFor($user->id, $criterion->toRule(), $rank->id) : null;
                $unmet = $res ? $this->evaluation->shortfall($prereq, $res) : array_merge($prereq['failures'] ?? [], [['text' => "No active requirement set for {$rank->name}"]]);
                $snapshot += ['prerequisites' => $prereq, 'evaluation' => $res];
                if ($unmet) {
                    $this->changes->assertOverrideAllowed($actor, $override, $justification, false, $rank, $unmet);
                    $snapshot['override'] = true;
                }
            }

            DB::table('users')->where('id', $user->id)->update([
                'rank_status' => $to, 'rank_status_changed_at' => now(), 'rank_changed_at' => now(),
            ]);

            return $this->audit->recordChange(
                $user, $rank->id, $rank->id, $type, $actor, $reason, $snapshot,
                $override && $justification ? trim($justification) : null,
            );
        });

        DB::afterCommit(fn () => SendInvestorRankNotificationJob::dispatch($change->id));
        if ($to === 'active') {
            rescue(fn () => EvaluateInvestorRankJob::dispatch($investor->id, 'rank_reinstated')->afterCommit(), null, true);
        }

        return $change;
    }
}
