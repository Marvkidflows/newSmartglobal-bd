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
use App\Services\Ledger\LedgerReaders;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RankInvestmentLedger
{
    /**
     * Per-investor facts the rank engine and every rank display use. ONE place decides what counts.
     *
     * Board decision §1 + Financial Team §3D: the monetary measure is CURRENT VERIFIED ELIGIBLE PRINCIPAL — verified principal still
     * invested in qualifying ACTIVE positions, after any completed principal reductions. A position that has completed, been
     * redeemed or been paid out no longer counts toward money, but its verified completed cycle keeps counting toward the cycle
     * requirement. Each record counts once; cancelled, duplicate, disputed, reversed, under-review, unverified, stale and
     * unresolved-currency records count for nothing.
     *
     * `total_usd` is kept as the HISTORICAL verified total (reporting only; it must never decide a rank).
     *
     * @param  int[]  $userIds
     * @param  bool   $withBreakdown  also return the per-position calculation breakdown (Financial Team §3D)
     * @return array<int, array<string, mixed>>
     */
    public function forUsers(array $userIds, bool $withBreakdown = false): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        $cents = [];
        $calculatedAt = now()->toIso8601String();
        foreach ($userIds as $id) {
            $out[$id] = ['total_usd' => 0.0, 'active_usd' => 0.0, 'current_principal' => 0.0, 'cycles' => 0, 'count' => 0,
                         'unverified' => [], 'stale' => [], 'duplicates' => [], 'blocked' => [], 'cycles_awaiting_verification' => [],
                         'cycle_references' => [], 'by_currency' => [], 'calculated_at' => $calculatedAt, 'breakdown' => []];
            $cents[$id] = 0;
        }
        if (!$userIds) return $out;

        $rows = DB::table('investment_accounts as ia')
            ->leftJoin('investment_plans as p', 'p.id', '=', 'ia.investment_plan_id')
            ->whereIn('ia.user_id', $userIds)
            ->orderBy('ia.id')
            ->get(['ia.id', 'ia.user_id', 'ia.investment_plan_id', 'ia.amount', 'ia.status', 'ia.start_date',
                   'ia.end_date', 'ia.created_at', 'ia.source_deposit_id', 'p.duration_days']);

        $ids        = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $valuations = $this->latestValuations('investment', $ids);
        $reductions = LedgerReaders::reductions($ids);
        $blocked    = LedgerReaders::blocked($ids, $rows->pluck('source_deposit_id', 'id')->map(fn ($v) => $v ? (int) $v : null)->all());
        $window     = max(0, (int) config('ranks.duplicate_window_seconds', 60));
        $today      = Carbon::today();
        $seen       = [];   // "user|plan|amount|start" => [created_at, ...] of earlier non-cancelled records

        $add = function (int $u, $r, string $status, ?float $verifiedUsd, float $remaining, float $principalUsd, bool $cycleCounted, array $exclusions) use (&$out, $withBreakdown) {
            if (!$withBreakdown) return;
            $out[$u]['breakdown'][] = [
                'investment_id' => (int) $r->id, 'reference' => 'INV-' . str_pad((string) $r->id, 6, '0', STR_PAD_LEFT), 'status' => $status,
                'amount' => round((float) $r->amount, 2), 'verified_usd' => $verifiedUsd, 'remaining_principal' => round($remaining, 2),
                'principal_usd_counted' => round($principalUsd, 2), 'cycle_reference' => 'CYC-' . str_pad((string) $r->id, 6, '0', STR_PAD_LEFT),
                'cycle_counted' => $cycleCounted, 'exclusions' => $exclusions,
            ];
        };

        foreach ($rows as $r) {
            $u      = (int) $r->user_id;
            $amount = round((float) $r->amount, 2);

            if ($r->status === 'cancelled') { $add($u, $r, 'cancelled', null, 0.0, 0.0, false, ['Cancelled']); continue; }   // excluded outright

            // Disputed / reversed / under review / unresolved currency metadata: excluded from money AND cycles (Board §3, Financial §5).
            if (isset($blocked[$r->id])) {
                $out[$u]['blocked'][] = (int) $r->id;
                $add($u, $r, 'under_review', null, $amount, 0.0, false, [$blocked[$r->id] === 'reconciliation' ? 'Currency metadata unresolved (in the reconciliation queue)' : 'Flagged: ' . str_replace('_', ' ', $blocked[$r->id])]);
                continue;
            }

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
                $add($u, $r, 'under_review', null, $amount, 0.0, false, ['Possible duplicate — awaiting review']);
                continue;
            }

            $out[$u]['count']++;

            // A cycle can only count when the record behind it is VERIFIED (framework §2: "only verified, eligible investments
            // may count"; "only cycles confirmed as completed by the financial system"). Completion is the financial system's
            // status + the scheduled end having passed; verification is Financial's recorded, current USD valuation.
            $completedCycle = $r->status === 'completed' && $amount > 0 && $this->cycleHasEnded($r, $today);

            if (!$val) {
                $out[$u]['unverified'][] = (int) $r->id;
                if ($completedCycle) $out[$u]['cycles_awaiting_verification'][] = (int) $r->id;
                $add($u, $r, $r->status === 'completed' ? 'completed_pending_verification' : 'active', null, $amount, 0.0, false, ['No verified USD valuation']);
            } elseif (abs($val['original_amount'] - $amount) > 0.004) {
                $out[$u]['stale'][] = (int) $r->id;                        // amount changed since it was verified
                if ($completedCycle) $out[$u]['cycles_awaiting_verification'][] = (int) $r->id;
                $add($u, $r, $r->status === 'completed' ? 'completed_pending_verification' : 'active', null, $amount, 0.0, false, ['Valuation is stale (amount changed after verification)']);
            } else {
                $out[$u]['total_usd'] += $val['usd_amount'];               // historical total (reporting only)

                // CURRENT principal: only ACTIVE positions, net of completed principal reductions (never below zero).
                $remaining = max(0.0, $amount - (float) ($reductions[$r->id] ?? 0));
                $principalUsd = 0.0;
                if ($r->status === 'active') {
                    $principalUsd = $this->principalUsd($val, $amount, $remaining);
                    $out[$u]['active_usd'] += $val['usd_amount'];
                    $cents[$u] += (int) round($principalUsd * 100);
                }

                // Transparency only: what each COUNTED record was worth in its own currency and at which recorded rate.
                $c = $val['currency'];
                $b = &$out[$u]['by_currency'][$c];
                $b ??= ['currency' => $c, 'records' => 0, 'original_total' => 0.0, 'usd_total' => 0.0, 'rates' => []];
                $b['records']++; $b['original_total'] += $val['original_amount']; $b['usd_total'] += $val['usd_amount'];
                if (!in_array($val['fx_rate'], $b['rates'], true)) $b['rates'][] = $val['fx_rate'];
                unset($b);

                if ($completedCycle) {
                    $out[$u]['cycles']++;                                  // verified AND completed AND matured → counted exactly once
                    $out[$u]['cycle_references'][] = 'CYC-' . str_pad((string) $r->id, 6, '0', STR_PAD_LEFT);
                }

                $notes = [];
                if ($r->status === 'completed') $notes[] = 'Completed — principal is no longer an active position (the cycle still counts)';
                if ($r->status === 'active' && $remaining <= 0) $notes[] = 'Principal fully redeemed';
                if ($r->status === 'active' && $remaining > 0 && $remaining < $amount) $notes[] = 'Partly redeemed — only the remaining principal counts';
                $add($u, $r, $r->status === 'completed' ? ($completedCycle ? 'completed_verified' : 'completed_pending_verification') : 'active',
                    (float) $val['usd_amount'], $remaining, $principalUsd, $completedCycle, $notes);
            }
        }

        foreach ($out as $u => $f) {
            $out[$u]['total_usd']  = round($f['total_usd'], 2);
            $out[$u]['active_usd'] = round($f['active_usd'], 2);
            $out[$u]['current_principal'] = $cents[$u] / 100;
            $out[$u]['by_currency'] = collect($f['by_currency'])->map(fn ($b) => [
                'currency' => $b['currency'], 'records' => $b['records'], 'original_total' => round($b['original_total'], 2),
                'usd_total' => round($b['usd_total'], 2), 'rates' => $b['rates'],
            ])->sortKeys()->values()->all();
        }

        return $out;
    }

    /** USD value of the REMAINING principal of one position. Exact decimal half-up at posting; an untouched position is its verified USD amount. */
    protected function principalUsd(array $val, float $amount, float $remaining): float
    {
        $usd = (float) $val['usd_amount'];
        if ($remaining >= $amount) return $usd;
        if ($remaining <= 0) return 0.0;

        $reduced = $amount - $remaining;
        $rate = $val['fx_rate'] ?? null;
        if (($rate === null || (float) $rate <= 0) && ($val['currency'] ?? null) === 'USD') $rate = 1;
        if ($rate === null || (float) $rate <= 0) {
            return round($usd * $remaining / $amount, 2);                   // no stored rate: proportional share of the verified value
        }
        $reducedUsd = (float) Decimal::mulHalfUp(Decimal::norm($reduced), Decimal::norm($rate), 2);
        return max(0.0, round($usd - $reducedUsd, 2));
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
