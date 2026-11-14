<?php
// LOCATION: app/Services/Rank/RankProgressNoticeService.php
//
// "You are approaching the next rank" notices (audit R-32), for every step Bronze → Silver … Diamond → Elite.
//
// SOURCE OF TRUTH: InvestorRankProgress::for() — the very calculation behind the investor's rank dashboard (verified current principal,
// verified completed cycles, the engine's own qualification test). Deposits, referrals, unverified or stale currency records and excluded
// transactions never reach it, so they can never produce a notice.
//
// A notice is sent only when ALL of these hold:
//   • the investor is an active investor holding an active rank (nothing for unranked, suspended or revoked investors);
//   • the next rank exists (nothing for the highest rank) and its criteria are ACTIVE (state "in_progress", never "awaiting_approval");
//   • the investor does not already meet the requirements (that is announced by the eligibility notice, not by this one);
//   • progress — the LOWER of the money bar and the cycle bar — has reached a configured milestone (config ranks.progress_notices.milestones).
//
// DUPLICATES / RE-ARMING: a row per (investor, next rank, milestone) is kept active while the investor stays at or above the milestone, so the
// daily sweep never repeats a notice. If progress later falls more than ranks.progress_notices.rearm_buffer_percent points BELOW a
// milestone the row is re-armed (active = NULL) and the notice can be sent again when the investor climbs back. A jump over several milestones
// at once sends ONE notice (for the highest) and records them all. The unique index (investor, rank, milestone, active) makes a racing second
// worker fail instead of duplicating.
//
// The notice never says a rank has been awarded. Meeting the requirements is announced by RankBenefitService::notifyNextRankEligibility.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\RankProgressNotice;
use App\Models\User;
use App\Notifications\InvestorRankNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class RankProgressNoticeService
{
    public function __construct(protected InvestorRankProgress $progress) {}

    public function enabled(): bool
    {
        return (bool) config('ranks.progress_notices.enabled', true);
    }

    /** @return int[] sorted, unique, 1..99 */
    public function milestones(): array
    {
        $raw = config('ranks.progress_notices.milestones', [75, 90]);
        if (is_string($raw)) $raw = explode(',', $raw);

        return collect((array) $raw)->map(fn ($v) => (int) $v)->filter(fn ($v) => $v >= 1 && $v <= 99)->unique()->sort()->values()->all();
    }

    protected function rearmBuffer(): int
    {
        return max(0, (int) config('ranks.progress_notices.rearm_buffer_percent', 5));
    }

    /**
     * @return array{status:string, reason:?string, sent:?int, recorded:int[], rearmed:int[], percent:?int}
     *   status: "sent" | "recorded" (state changed, nothing to send) | "unchanged" | "skipped"
     */
    public function process(User $user): array
    {
        $out = ['status' => 'skipped', 'reason' => null, 'sent' => null, 'recorded' => [], 'rearmed' => [], 'percent' => null];

        if (!$this->enabled())                                                   return $this->skip($out, 'disabled');
        if ($user->role !== 'investor')                                          return $this->skip($out, 'not_an_investor');
        if (!in_array($user->status ?? 'active', ['active'], true))              return $this->skip($out, 'account_not_active');
        if (!$user->investor_rank_id)                                            return $this->skip($out, 'unranked');
        if (($user->rank_status ?? 'active') !== 'active')                       return $this->skip($out, 'rank_not_active');
        if (!$this->milestones())                                                return $this->skip($out, 'no_milestones');

        $p = $this->progress->for($user);
        if (($p['state'] ?? null) !== 'in_progress')                             return $this->skip($out, (string) ($p['state'] ?? 'unknown'));

        $ranks   = InvestorRank::orderBy('level')->get();
        $current = $ranks->firstWhere('id', $user->investor_rank_id);
        $next    = $ranks->first(fn ($r) => $r->level > ($current?->level ?? 0));
        if (!$next)                                                              return $this->skip($out, 'highest');

        $parts = array_values(array_filter([$p['amount']['percent'] ?? null, $p['cycles']['percent'] ?? null], fn ($v) => $v !== null));
        if (!$parts)                                                             return $this->skip($out, 'no_measurable_progress');

        $pct = (int) min($parts);
        $out['percent'] = $pct;

        try {
            return DB::transaction(fn () => $this->apply($user, $next, $p, $pct, $out));
        } catch (UniqueConstraintViolationException) {
            // Another worker recorded the same milestone a moment ago: its notice stands, ours is not needed.
            return $this->skip($out, 'concurrent_duplicate');
        }
    }

    protected function apply(User $user, InvestorRank $next, array $p, int $pct, array $out): array
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();   // serialise two sweeps over the same investor (no-op on SQLite)

        $active = RankProgressNotice::query()
            ->where('investor_id', $user->id)->where('next_rank_id', $next->id)->where('active', true)
            ->lockForUpdate()->get()->keyBy('milestone');

        $new = []; $rearmed = [];
        foreach ($this->milestones() as $m) {
            if ($pct >= $m) {
                if (!$active->has($m)) $new[] = $m;
            } elseif ($active->has($m) && $pct < $m - $this->rearmBuffer()) {
                $active[$m]->forceFill(['active' => null, 'reset_at' => now()])->save();
                $rearmed[] = $m;
            }
        }
        $out['rearmed'] = $rearmed;

        if (!$new) {
            $out['status'] = $rearmed ? 'recorded' : 'unchanged';
            return $out;
        }

        $top  = max($new);
        $rows = [];
        foreach ($new as $m) {
            $rows[$m] = RankProgressNotice::create([
                'investor_id' => $user->id, 'next_rank_id' => $next->id, 'milestone' => $m, 'percent_at_notice' => $pct,
                'amount_percent' => $p['amount']['percent'] ?? null, 'cycles_percent' => $p['cycles']['percent'] ?? null,
                'active' => true, 'carried_notification' => $m === $top,
            ]);
        }

        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'ssi:approaching-rank:' . $rows[$top]->id)->toString();
        $user->notify(new InvestorRankNotification($id, $this->payload($next, $p, $pct, $top)));   // throws → the whole transaction rolls back and the sweep can retry
        $rows[$top]->forceFill(['notification_id' => $id, 'notified_at' => now()])->save();

        $out['status']   = 'sent';
        $out['sent']     = $top;
        $out['recorded'] = $new;
        return $out;
    }

    /** What the investor is told. It names exactly what is still missing, never claims a rank was awarded, and never mentions anything unverified. */
    protected function payload(InvestorRank $next, array $p, int $pct, int $milestone): array
    {
        $missing = [];
        if (!empty($p['amount']) && (float) $p['amount']['remaining'] > 0) {
            $missing[] = '$' . number_format((float) $p['amount']['remaining'], 2) . ' more in active invested principal';
        }
        if (!empty($p['cycles']) && (int) $p['cycles']['remaining'] > 0) {
            $n = (int) $p['cycles']['remaining'];
            $missing[] = $n . ' more completed investment ' . ($n === 1 ? 'cycle' : 'cycles');
        }

        $message = "You are {$pct}% of the way to the {$next->name} rank, based on your verified investments."
            . ($missing ? ' To qualify you still need ' . implode(' and ', $missing) . '.' : '')
            . ' Deposits, pending withdrawals and records still awaiting verification are not counted.'
            . (config('ranks.require_approval', true)
                ? ' When you meet the requirements your rank is awarded only after SSI reviews and approves it.'
                : ' When you meet the requirements your rank is updated at the next evaluation.');

        return [
            'type' => 'rank_update', 'event' => 'approaching_next_rank', 'title' => "You're getting close to {$next->name}",
            'message' => $message, 'next_rank' => $next->name, 'percent' => $pct, 'milestone' => $milestone,
            'amount_percent' => $p['amount']['percent'] ?? null, 'cycles_percent' => $p['cycles']['percent'] ?? null,
            'link' => '/investor/rank',
        ];
    }

    protected function skip(array $out, string $reason): array
    {
        $out['status'] = 'skipped';
        $out['reason'] = $reason;
        return $out;
    }
}
