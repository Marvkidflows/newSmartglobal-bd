<?php
// LOCATION: app/Http/Controllers/Admin/RankStandingController.php
//
// Per-investor CALCULATED eligibility (kept separate from the assigned rank), suspend / revoke / reinstate,
// and CSV export for authorised reporting. Backend Gates on every action.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\RankReview;
use App\Models\User;
use App\Services\Rank\RankEligibilityCalculator;
use App\Services\Rank\RankPresenter;
use App\Services\Rank\RankStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RankStandingController extends Controller
{
    public function __construct(
        protected RankEligibilityCalculator $calculator,
        protected RankStatusService $status,
        protected RankPresenter $present,
    ) {}

    // GET …/investors/{user}/eligibility — assigned rank vs calculated eligibility, every requirement met / unmet
    public function eligibility(User $user)
    {
        abort_unless(Gate::any(['ranks.view', 'ranks.review']), 403);
        abort_unless($user->role === 'investor', 404);
        $user->loadMissing('investorRank');

        return response()->json($this->calculator->calculate($user));
    }

    // POST /admin/ranks/investors/{user}/suspend|revoke   { reason }
    public function suspend(Request $request, User $user)
    {
        abort_unless($user->role === 'investor', 404);
        Gate::authorize('ranks.suspend');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->respond($this->status->suspend($user, $request->user(), $v['reason']), $user);
    }

    public function revoke(Request $request, User $user)
    {
        abort_unless($user->role === 'investor', 404);
        Gate::authorize('ranks.revoke');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->respond($this->status->revoke($user, $request->user(), $v['reason']), $user);
    }

    // POST /admin/ranks/investors/{user}/reinstate   { reason, override?, override_justification? }
    public function reinstate(Request $request, User $user)
    {
        abort_unless($user->role === 'investor', 404);
        Gate::authorize('ranks.assign');
        $v = $request->validate([
            'reason' => 'required|string|min:5|max:1000', 'override' => 'nullable|boolean',
            'override_justification' => 'nullable|string|max:1000',
        ]);

        return $this->respond($this->status->reinstate($user, $request->user(), $v['reason'], (bool) ($v['override'] ?? false), $v['override_justification'] ?? null), $user);
    }

    protected function respond(InvestorRankChange $change, User $user)
    {
        $change->load(['previousRank', 'newRank']);
        return response()->json(['change' => $this->present->change($change), 'rank_status' => $user->fresh()->rank_status]);
    }

    // GET …/export?type=investors|history&rank=&status=
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('ranks.export');
        $v = $request->validate(['type' => 'nullable|in:investors,history', 'rank' => 'nullable|string|max:20', 'status' => 'nullable|in:active,suspended,revoked']);
        $type = $v['type'] ?? 'investors';
        $file = 'ssi-rank-' . $type . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($type, $v) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so spreadsheets read names correctly

            if ($type === 'history') {
                fputcsv($out, ['Reference', 'Date (UTC)', 'Account ID', 'Previous rank', 'New rank', 'Change type', 'Automatic', 'Responsible', 'Role', 'Reason', 'Override justification']);
                InvestorRankChange::with(['previousRank', 'newRank'])->orderBy('id')->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $c) {
                        fputcsv($out, array_map([$this, 'safe'], [
                            $c->reference, $c->created_at?->utc()->format('Y-m-d H:i:s'), $c->account_id, $c->previousRank?->name ?? 'Unranked',
                            $c->newRank?->name, $c->change_type, $c->is_automatic ? 'yes' : 'no', $c->actor_name, $c->actor_role, $c->reason, $c->override_justification,
                        ]));
                    }
                });
                // Approval decisions (approve / reject / withdraw) are part of the rank history too.
                fputcsv($out, []);
                fputcsv($out, ['Review reference', 'Created (UTC)', 'Account ID', 'Current rank', 'Proposed rank', 'Status', 'Decided by', 'Role', 'Decision reason', 'Decided (UTC)']);
                RankReview::with(['currentRank', 'proposedRank'])->orderBy('id')->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, array_map([$this, 'safe'], [
                            $r->reference, $r->created_at?->utc()->format('Y-m-d H:i:s'), $r->account_id, $r->currentRank?->name ?? 'Unranked', $r->proposedRank?->name,
                            $r->status, $r->decided_by_name, $r->decided_by_role, $r->decision_reason, $r->decided_at?->utc()->format('Y-m-d H:i:s'),
                        ]));
                    }
                });
                fclose($out);
                return;
            }

            fputcsv($out, ['Account ID', 'Name', 'Email', 'Assigned rank', 'Rank status', 'Rank changed', 'Calculated eligible rank', 'Pending review for',
                'Verified total invested (USD)', 'Completed cycles', 'Qualified referrals', 'Active investment (USD)', 'Approved deposits (USD)',
                'Qualifying investments', 'Longest duration (days)', 'Unmet requirements for next rank']);

            $q = User::query()->where('role', 'investor')->with('investorRank')->orderBy('id');
            if (!empty($v['status'])) $q->where('rank_status', $v['status']);
            if (!empty($v['rank'])) {
                if ($v['rank'] === 'none') $q->whereNull('investor_rank_id');
                else $q->whereIn('investor_rank_id', InvestorRank::where('key', $v['rank'])->pluck('id'));
            }

            $q->chunkById(100, function ($users) use ($out) {
                foreach ($this->calculator->calculateMany($users) as $row) {
                    $next = collect($row['ranks'])->first(fn ($r) => $r['rank']['level'] > ($row['assigned_rank']['level'] ?? 0));
                    $a = $row['additional_values'];
                    fputcsv($out, array_map([$this, 'safe'], [
                        $row['account_id'], $row['name'], $row['email'], $row['assigned_rank']['name'] ?? 'Unranked', $row['rank_status'],
                        null, $row['calculated_rank']['name'] ?? 'None', $row['pending_review']['proposed_rank']['name'] ?? '',
                        $row['core_values']['verified_total_invested_usd'], $row['core_values']['completed_cycles'],
                        $a['qualified_referrals']['value'] ?? 0, $a['active_investment_usd']['value'] ?? 0, $a['approved_deposits_usd']['value'] ?? 0,
                        $a['qualifying_investments']['value'] ?? 0, $a['longest_duration_days']['value'] ?? 0,
                        $next ? implode(' | ', $next['unmet']) : '',
                    ]));
                }
            });
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet-formula injection from user-controlled text (names, reasons). */
    public function safe(mixed $v): mixed
    {
        if (!is_string($v) || $v === '') return $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}
