<?php
// LOCATION: app/Services/Rank/RankReportService.php
//
// READ-ONLY investor report: investment-cycle history and consolidated records (Framework §3 C–F "performance summary and
// cycle history", "enhanced reporting", "consolidated cycle records"). It only RE-PRESENTS what the rank engine already
// decided (RankInvestmentLedger): which records are counted, which are not and why. It re-implements no rule, writes
// nothing, and shows the investor ONLY their own records.
//
// Availability follows the benefits catalogue: a section appears only when an authorised administrator has enabled the
// matching benefit for the investor's rank. Until then `available` is false and no data is returned.
//
// Facts only: the amounts and terms recorded on each investment. No projection, no promise of profit.

namespace App\Services\Rank;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RankReportService
{
    public const CYCLE_HISTORY  = ['gold.performance-summary', 'platinum.performance-summary', 'diamond.enhanced-reporting', 'elite.highest-reporting', 'gold.extra-reporting'];
    public const PLAN_BREAKDOWN = ['platinum.enhanced-reporting', 'diamond.enhanced-reporting', 'elite.highest-reporting'];
    public const CONSOLIDATED   = ['diamond.enhanced-reporting', 'elite.highest-reporting'];

    public function __construct(protected RankBenefitService $benefits, protected RankInvestmentLedger $ledger) {}

    public function for(User $user): array
    {
        $sections = [
            'cycle_history'  => $this->benefits->enabledFor($user, self::CYCLE_HISTORY),
            'plan_breakdown' => $this->benefits->enabledFor($user, self::PLAN_BREAKDOWN),
            'consolidated'   => $this->benefits->enabledFor($user, self::CONSOLIDATED),
        ];
        $available = in_array(true, $sections, true);

        $out = ['available' => $available, 'sections' => $sections,
                'message' => $available ? null : 'Investment reports are rank benefits that SSI management has not enabled for your rank yet.'];
        if (!$available) return $out;

        $facts = $this->ledger->forUsers([$user->id], true)[$user->id];
        $principalById = collect($facts['breakdown'])->keyBy('investment_id');
        $rows = DB::table('investment_accounts as ia')
            ->leftJoin('investment_plans as p', 'p.id', '=', 'ia.investment_plan_id')
            ->where('ia.user_id', $user->id)->orderBy('ia.id')
            ->get(['ia.id', 'ia.investment_plan_id', 'ia.amount', 'ia.status', 'ia.start_date', 'ia.end_date', 'ia.profit_percentage',
                   'ia.expected_profit', 'ia.total_return', 'p.name as plan_name', 'p.duration_days']);
        $valuations = $this->ledger->latestValuations('investment', $rows->pluck('id')->all());
        $today = Carbon::today();

        $unverified = array_flip($facts['unverified']); $stale = array_flip($facts['stale']); $dups = array_flip($facts['duplicates']);
        $cycles = [];
        foreach ($rows as $r) {
            // Mirrors RankInvestmentLedger exactly. A record counts — its amount AND its cycle — only once Financial has verified its
            // USD value and that valuation is current. A cycle additionally needs: status completed, an amount, and the scheduled
            // end passed. Cancelled and possible-duplicate records count for nothing.
            $awaitingValue = isset($unverified[$r->id]) || isset($stale[$r->id]);
            $excluded = $r->status === 'cancelled' || isset($dups[$r->id]);
            $amountCounted = !$excluded && !$awaitingValue;

            $reason = null; $cycleCounted = false;
            if ($r->status === 'cancelled')                          $reason = 'Cancelled';
            elseif (isset($dups[$r->id]))                            $reason = 'Possible duplicate — awaiting review';
            elseif ($r->status !== 'completed')                      $reason = 'Cycle not completed yet';
            elseif ((float) $r->amount <= 0)                         $reason = 'No amount recorded';
            elseif (!$this->ledger->cycleHasEnded($r, $today))       $reason = 'Scheduled end date not reached';
            elseif ($awaitingValue)                                  $reason = 'Awaiting currency verification';
            else                                                     $cycleCounted = true;

            $val = $valuations[$r->id] ?? null;
            $cycles[] = [
                'id' => (int) $r->id, 'plan' => $r->plan_name, 'plan_id' => (int) $r->investment_plan_id,
                'amount' => (float) $r->amount, 'verified_usd' => ($val && !isset($stale[$r->id]) && !isset($dups[$r->id])) ? (float) $val['usd_amount'] : null,
                'status' => $r->status, 'start_date' => $r->start_date ? substr((string) $r->start_date, 0, 10) : null,
                'end_date' => $r->end_date ? substr((string) $r->end_date, 0, 10) : null,
                'cycle_counted' => $cycleCounted, 'not_counted_reason' => $reason,
                'current_principal_usd' => (float) ($principalById[(int) $r->id]['principal_usd_counted'] ?? 0),   // what this record contributes to current eligible principal (0 once completed)
                'amount_counted' => $amountCounted,
                'amount_note' => ($awaitingValue && !$excluded) ? 'Not counted until its currency is verified' : null,
                // The terms RECORDED on this investment when it was created (contractual return stays as recorded).
                'recorded_terms' => ['profit_percentage' => (float) $r->profit_percentage, 'expected_profit' => (float) $r->expected_profit, 'total_return' => (float) $r->total_return],
            ];
        }

        $out['summary'] = [
            'current_eligible_principal' => $facts['current_principal'],   // the monetary rank measure
            'verified_usd_total' => $facts['total_usd'],                   // historical total (reporting only; never decides rank)
            'completed_cycles_counted' => $facts['cycles'], 'records' => count($cycles),
            'not_counted' => count(array_filter($cycles, fn ($c) => !$c['cycle_counted'] || !$c['amount_counted'])),
            'note' => 'Current eligible principal is the verified principal still invested in active positions; the historical total is for reporting only. A record counts — its amount and its cycle — only once its currency has been verified. A cycle also needs to be completed with its scheduled end passed. Cancelled and possible-duplicate records never count. Withdrawals do not change these figures.',
        ];
        if ($sections['cycle_history']) $out['cycles'] = $cycles;

        if ($sections['plan_breakdown']) {
            $out['by_plan'] = collect($cycles)->groupBy('plan')->map(fn ($g, $plan) => [
                'plan' => $plan, 'records' => $g->count(), 'completed_cycles_counted' => $g->where('cycle_counted', true)->count(),
                'verified_usd' => round($g->filter(fn ($c) => $c['amount_counted'])->sum(fn ($c) => $c['verified_usd'] ?? 0), 2),
            ])->values()->all();
        }
        if ($sections['consolidated']) {
            $out['by_year'] = collect($cycles)->filter(fn ($c) => $c['start_date'])->groupBy(fn ($c) => substr($c['start_date'], 0, 4))->sortKeys()
                ->map(fn ($g, $year) => [
                    'year' => (string) $year, 'records' => $g->count(), 'completed_cycles_counted' => $g->where('cycle_counted', true)->count(),
                    'verified_usd' => round($g->filter(fn ($c) => $c['amount_counted'])->sum(fn ($c) => $c['verified_usd'] ?? 0), 2),
                ])->values()->all();
        }

        return $out;
    }
}
