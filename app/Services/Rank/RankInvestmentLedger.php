<?php
// LOCATION: app/Services/Rank/RankInvestmentLedger.php
//
// READ-ONLY. Turns raw investment / deposit rows into the facts the rank engine may rely on, applying — in one
// place, so every rank field agrees — Management's rules:
//
//  • AMOUNT (USD): only a record with a current, matching, VERIFIED USD valuation contributes. Anything else
//    contributes $0 and is reported as unverified/stale for review. A stored number is never assumed to be USD.
//  • CANCELLED investments are excluded.
//  • DUPLICATES are excluded: a record that has the same investor, plan, amount and start date as an EARLIER
//    non-cancelled record and was created within `ranks.duplicate_window_seconds` of it (the signature of a
//    double-submit; the wallet-funded path has no lock or idempotency key) — unless Financial confirmed it is a
//    separate genuine investment. Each investment row can contribute at most once.
//  • COMPLETED CYCLE: status = completed, amount > 0, AND the cycle has genuinely ended: both the recorded end
//    date and the plan-scheduled end (start date + plan duration) have been reached. Requiring both means an
//    Admin completing early — or shortening the countdown — cannot make a cycle count before its scheduled end,
//    while a legitimately extended investment does not count until the extended date.
//  • REINVESTMENT: no marker exists in the data and the funding source proves nothing, so none is used. Every
//    separate genuine record is its own record; editing/extending an investment changes the SAME row and so can
//    never add a cycle.

namespace App\Services\Rank;

use App\Models\InvestmentAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RankInvestmentLedger
{
    /**
     * @param  int[]  $userIds
     * @return array<int, array{total_usd: float, active_usd: float, cycles: int, count: int,
     *                          unverified: int[], stale: int[], duplicates: int[], by_currency: array}>
     */
    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = ['total_usd' => 0.0, 'active_usd' => 0.0, 'cycles' => 0, 'count' => 0,
                         'unverified' => [], 'stale' => [], 'duplicates' => [], 'by_currency' => []];
        }
        if (!$userIds) return $out;

        $rows = DB::table('investment_accounts as ia')
            ->leftJoin('investment_plans as p', 'p.id', '=', 'ia.investment_plan_id')
            ->whereIn('ia.user_id', $userIds)
            ->orderBy('ia.id')
            ->get(['ia.id', 'ia.user_id', 'ia.investment_plan_id', 'ia.amount', 'ia.status', 'ia.start_date',
                   'ia.end_date', 'ia.created_at', 'p.duration_days']);

        $valuations = $this->latestValuations('investment', $rows->pluck('id')->all());
        $window     = max(0, (int) config('ranks.duplicate_window_seconds', 60));
        $today      = Carbon::today();
        $seen       = [];   // "user|plan|amount|start" => [created_at, ...] of earlier non-cancelled records

        foreach ($rows as $r) {
            if ($r->status === 'cancelled') continue;                      // excluded outright

            $u      = (int) $r->user_id;
            $amount = round((float) $r->amount, 2);
            $key    = $u . '|' . $r->investment_plan_id . '|' . number_format($amount, 2, '.', '') . '|' . substr((string) $r->start_date, 0, 10);
            $created = $r->created_at ? Carbon::parse($r->created_at) : null;

            $isDuplicate = false;
            foreach ($seen[$key] ?? [] as $earlier) {
                if ($created && $earlier && abs($created->diffInSeconds($earlier, true)) <= $window) { $isDuplicate = true; break; }
            }
            $seen[$key][] = $created;

            $val = $valuations[$r->id] ?? null;
            if ($isDuplicate && !($val && $val['distinct_confirmed'])) {
                $out[$u]['duplicates'][] = (int) $r->id;                   // excluded + flagged
                continue;
            }

            $out[$u]['count']++;

            if (!$val) {
                $out[$u]['unverified'][] = (int) $r->id;
            } elseif (abs($val['original_amount'] - $amount) > 0.004) {
                $out[$u]['stale'][] = (int) $r->id;                        // amount changed since it was verified
            } else {
                $out[$u]['total_usd'] += $val['usd_amount'];
                if ($r->status === 'active') $out[$u]['active_usd'] += $val['usd_amount'];

                // Transparency only: what each COUNTED record was worth in its own currency and at which recorded rate.
                $c = $val['currency'];
                $b = &$out[$u]['by_currency'][$c];
                $b ??= ['currency' => $c, 'records' => 0, 'original_total' => 0.0, 'usd_total' => 0.0, 'rates' => []];
                $b['records']++; $b['original_total'] += $val['original_amount']; $b['usd_total'] += $val['usd_amount'];
                if (!in_array($val['fx_rate'], $b['rates'], true)) $b['rates'][] = $val['fx_rate'];
                unset($b);
            }

            if ($r->status === 'completed' && $amount > 0 && $this->cycleHasEnded($r, $today)) {
                $out[$u]['cycles']++;
            }
        }

        foreach ($out as $u => $f) {
            $out[$u]['total_usd']  = round($f['total_usd'], 2);
            $out[$u]['active_usd'] = round($f['active_usd'], 2);
            $out[$u]['by_currency'] = collect($f['by_currency'])->map(fn ($b) => [
                'currency' => $b['currency'], 'records' => $b['records'], 'original_total' => round($b['original_total'], 2),
                'usd_total' => round($b['usd_total'], 2), 'rates' => $b['rates'],
            ])->sortKeys()->values()->all();
        }

        return $out;
    }

    /** Verified-USD total of APPROVED deposits per investor (same valuation rules). @return array<int, array{total_usd: float, unverified: int[], stale: int[]}> */
    public function depositsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        foreach ($userIds as $id) $out[$id] = ['total_usd' => 0.0, 'unverified' => [], 'stale' => []];
        if (!$userIds) return $out;

        $rows = DB::table('deposits')->whereIn('user_id', $userIds)->where('status', 'approved')->orderBy('id')->get(['id', 'user_id', 'amount']);
        $valuations = $this->latestValuations('deposit', $rows->pluck('id')->all());

        foreach ($rows as $r) {
            $u = (int) $r->user_id; $val = $valuations[$r->id] ?? null; $amount = round((float) $r->amount, 2);
            if (!$val) $out[$u]['unverified'][] = (int) $r->id;
            elseif (abs($val['original_amount'] - $amount) > 0.004) $out[$u]['stale'][] = (int) $r->id;
            else $out[$u]['total_usd'] += $val['usd_amount'];
        }
        foreach ($out as $u => $f) $out[$u]['total_usd'] = round($f['total_usd'], 2);

        return $out;
    }

    /** The NEWEST valuation per record (multiple rows for one record never double count). @return array<int, array> */
    public function latestValuations(string $type, array $recordIds): array
    {
        if (!$recordIds) return [];

        $latest = []; $confirmed = [];
        foreach (array_chunk($recordIds, 500) as $chunk) {
            $rows = DB::table('usd_valuations')->where('valuable_type', $type)->whereIn('valuable_id', $chunk)
                ->orderBy('id')->get(['id', 'valuable_id', 'original_amount', 'usd_amount', 'currency', 'fx_rate', 'distinct_confirmed']);
            foreach ($rows as $v) {
                $confirmed[$v->valuable_id] = ($confirmed[$v->valuable_id] ?? false) || (bool) $v->distinct_confirmed;
                $latest[$v->valuable_id] = [
                    'id' => (int) $v->id, 'currency' => $v->currency, 'fx_rate' => (float) $v->fx_rate,
                    'original_amount' => round((float) $v->original_amount, 2), 'usd_amount' => round((float) $v->usd_amount, 2),
                    'distinct_confirmed' => false,
                ];
            }
        }
        foreach ($latest as $id => $_) $latest[$id]['distinct_confirmed'] = $confirmed[$id];   // confirmation, once given, stands

        return $latest;
    }

    /** The earlier record this one duplicates, if any (used when Financial verifies it). */
    public function duplicateOf(InvestmentAccount $investment): ?int
    {
        $window = max(0, (int) config('ranks.duplicate_window_seconds', 60));

        $earlier = InvestmentAccount::query()
            ->where('user_id', $investment->user_id)->where('investment_plan_id', $investment->investment_plan_id)
            ->where('amount', $investment->amount)->whereDate('start_date', Carbon::parse($investment->start_date)->toDateString())
            ->where('id', '<', $investment->id)->where('status', '!=', 'cancelled')->orderBy('id')->get();

        foreach ($earlier as $e) {
            if ($e->created_at && $investment->created_at && abs($e->created_at->diffInSeconds($investment->created_at, true)) <= $window) {
                return (int) $e->id;
            }
        }
        return null;
    }

    /** Both the recorded end date AND the plan-scheduled end (start + plan duration) must have been reached. */
    public function cycleHasEnded(object $r, Carbon $today): bool
    {
        if (!$r->end_date) return false;
        if (Carbon::parse($r->end_date)->startOfDay()->gt($today)) return false;

        if ($r->start_date && $r->duration_days) {
            $scheduled = Carbon::parse($r->start_date)->startOfDay()->addDays((int) $r->duration_days);
            if ($scheduled->gt($today)) return false;
        }
        return true;
    }
}
