<?php
// LOCATION: app/Http/Controllers/Admin/RankBenefitController.php
// Admin: benefits catalogue, incentive terms, audit trail, export. Gates are checked on EVERY action.
//   ranks.view            read the catalogue / history
//   ranks.manage-benefits edit wording, dates, publication; suspend; create; prepare incentive terms
//   ranks.approve-benefits switch a benefit on; approve / activate an incentive term (written approval reference required)
//   ranks.export          CSV

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestorConsentLog;
use App\Models\RankBenefit;
use App\Models\RankIncentiveTerm;
use App\Services\Rank\BenefitDefaults;
use App\Services\Rank\PrincipalPolicy;
use App\Services\Rank\RankBenefitService;
use App\Services\Rank\RankIncentiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RankBenefitController extends Controller
{
    public function __construct(protected RankBenefitService $benefits, protected RankIncentiveService $incentives, protected PrincipalPolicy $policy) {}

    // GET /admin/ranks/benefits
    public function index()
    {
        Gate::authorize('ranks.view');
        $catalogue = $this->benefits->adminCatalogue();
        $counts = RankBenefit::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'ranks'  => $catalogue,
            'counts' => collect(RankBenefit::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'status_labels' => RankBenefit::STATUS_LABELS,
            'categories' => RankBenefit::CATEGORIES,
            'incentives' => $this->incentives->list(),
            'incentives_activation_enabled' => (bool) config('ranks.incentives_activation_enabled', false),
            'consenting_investors' => (int) DB::table('investor_consent_log as c')
                ->where('c.purpose', InvestorConsentLog::RECOGNITION)->where('c.granted', true)
                ->whereRaw('c.id = (select max(id) from investor_consent_log where user_id = c.user_id and purpose = c.purpose)')->count(),
            'calculation_policy' => $this->calculationPolicy(),
            'principal_policy' => $this->policy->state(),
            'abilities' => [
                'manage'  => Gate::allows('ranks.manage-benefits'),
                'approve' => Gate::allows('ranks.approve-benefits'),
                'export'  => Gate::allows('ranks.export'),
                'confirm_policy' => Gate::allows('ranks.confirm-policy'),
            ],
        ]);
    }

    // POST /admin/ranks/benefits  { investor_rank_id, category, title, description, is_financial?, requires_consent?, reason }
    public function store(Request $request)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $request->validate([
            'investor_rank_id' => 'required|integer|exists:investor_ranks,id',
            'category' => ['required', Rule::in(RankBenefit::CATEGORIES)],
            'title' => 'required|string|min:3|max:160', 'description' => 'required|string|min:10|max:2000',
            'is_financial' => 'nullable|boolean', 'requires_consent' => 'nullable|boolean',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $b = $this->benefits->create($v, $request->user(), $v['reason']);

        return response()->json($this->benefits->adminRow($b), 201);
    }

    // PUT /admin/ranks/benefits/{benefit}  { title?, description?, display_order?, is_published?, effective_from?, effective_to?, reason }
    public function update(Request $request, RankBenefit $benefit)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $request->validate([
            'title' => 'sometimes|string|min:3|max:160', 'description' => 'sometimes|string|min:10|max:2000',
            'display_order' => 'sometimes|integer|min:0|max:65000', 'is_published' => 'sometimes|boolean',
            'effective_from' => 'sometimes|nullable|date', 'effective_to' => 'sometimes|nullable|date',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        return response()->json($this->benefits->adminRow($this->benefits->update($benefit, $v, $request->user(), $v['reason'])));
    }

    // POST /admin/ranks/benefits/{benefit}/status  { status, reason, approval_reference? }
    public function status(Request $request, RankBenefit $benefit)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $request->validate([
            'status' => ['required', Rule::in(RankBenefit::STATUSES)], 'reason' => 'required|string|min:5|max:1000',
            'approval_reference' => 'nullable|string|max:160',
        ]);

        return response()->json($this->benefits->adminRow(
            $this->benefits->setStatus($benefit, $v['status'], $request->user(), $v['reason'], $v['approval_reference'] ?? null)
        ));
    }

    // GET /admin/ranks/benefits/history?subject_type=&subject_id=
    public function history(Request $request)
    {
        Gate::authorize('ranks.view-audit');
        $v = $request->validate(['subject_type' => 'nullable|in:benefit,incentive,loan_programme,policy_decision,reconciliation,principal,flag', 'subject_id' => 'nullable|integer']);
        $page = $this->benefits->history($v);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    // POST /admin/ranks/principal-policy  { topic, status: confirmed|revoked, approval_reference, note? } — admin only, append-only, audited
    public function policyRecord(Request $request)
    {
        Gate::authorize('ranks.confirm-policy');
        $v = $request->validate([
            'topic' => ['required', Rule::in(array_keys(PrincipalPolicy::TOPICS))], 'status' => 'required|in:confirmed,revoked',
            'approval_reference' => 'required|string|min:5|max:160', 'note' => 'nullable|string|max:2000',
        ]);
        $this->policy->record($v['topic'], $v['status'], $v['approval_reference'], $v['note'] ?? null, $request->user());

        return response()->json($this->policy->state(), 201);
    }

    // ── Incentive terms (configurable, inactive until the approval chain is complete) ───────────────────

    public function incentiveStore(Request $request)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $this->incentiveRules($request);

        return response()->json($this->incentives->present($this->incentives->create($v, $request->user(), $v['reason'])), 201);
    }

    public function incentiveUpdate(Request $request, RankIncentiveTerm $term)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $this->incentiveRules($request, partial: true);

        return response()->json($this->incentives->present($this->incentives->update($term, $v, $request->user(), $v['reason'])));
    }

    public function incentiveSubmit(Request $request, RankIncentiveTerm $term)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->incentives->present($this->incentives->submit($term, $request->user(), $v['reason'])));
    }

    public function incentiveApprove(Request $request, RankIncentiveTerm $term)
    {
        Gate::authorize('ranks.approve-benefits');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000', 'approval_reference' => 'required|string|min:5|max:160']);

        return response()->json($this->incentives->present($this->incentives->approve($term, $request->user(), $v['reason'], $v['approval_reference'])));
    }

    public function incentiveActivate(Request $request, RankIncentiveTerm $term)
    {
        Gate::authorize('ranks.approve-benefits');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->incentives->present($this->incentives->activate($term, $request->user(), $v['reason'])));
    }

    public function incentiveDisable(Request $request, RankIncentiveTerm $term)
    {
        Gate::authorize('ranks.manage-benefits');
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->incentives->present($this->incentives->disable($term, $request->user(), $v['reason'])));
    }

    // GET /admin/ranks/benefits/export?type=benefits|consents
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('ranks.export');
        $v = $request->validate(['type' => 'nullable|in:benefits,consents']);
        $type = $v['type'] ?? 'benefits';
        $file = 'ssi-rank-' . $type . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($type) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            if ($type === 'consents') {
                fputcsv($out, ['Account ID', 'Name', 'Consent: recognition in private investor communications', 'Recorded (UTC)']);
                DB::table('investor_consent_log as c')->join('users as u', 'u.id', '=', 'c.user_id')
                    ->where('c.purpose', InvestorConsentLog::RECOGNITION)
                    ->whereRaw('c.id = (select max(id) from investor_consent_log where user_id = c.user_id and purpose = c.purpose)')
                    ->orderBy('c.user_id')->select('u.id', 'u.name', 'c.granted', 'c.created_at')
                    ->chunk(500, function ($rows) use ($out) {
                        foreach ($rows as $r) {
                            fputcsv($out, array_map([$this, 'safe'], [$this->accountId((int) $r->id), $r->name, $r->granted ? 'granted' : 'withdrawn', $r->created_at]));
                        }
                    });
            } else {
                fputcsv($out, ['Rank', 'Key', 'Category', 'Title', 'Status', 'Delivered by', 'Financial', 'Needs approval', 'Needs consent', 'Published', 'Effective from', 'Effective to', 'Approval reference', 'Approved by', 'Approved (UTC)']);
                RankBenefit::with('rank')->orderBy('investor_rank_id')->orderBy('display_order')->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $b) {
                        fputcsv($out, array_map([$this, 'safe'], [
                            $b->rank?->name, $b->key, $b->category, $b->title, RankBenefit::STATUS_LABELS[$b->status] ?? $b->status, $b->fulfilment,
                            $b->is_financial ? 'yes' : 'no', $b->requires_approval ? 'yes' : 'no', $b->requires_consent ? 'yes' : 'no', $b->is_published ? 'yes' : 'no',
                            $b->effective_from?->toDateString(), $b->effective_to?->toDateString(), $b->approval_reference, $b->approved_by_name, $b->approved_at?->utc()->format('Y-m-d H:i:s'),
                        ]));
                    }
                });
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    protected function incentiveRules(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'label' => "$req|string|min:3|max:160", 'investor_rank_id' => ($partial ? 'sometimes' : 'required') . '|integer|exists:investor_ranks,id',
            'investment_plan_id' => 'sometimes|nullable|integer', 'kind' => "$req|in:fixed_bonus,percentage_bonus",
            'value' => 'sometimes|nullable|numeric|min:0', 'conditions' => 'sometimes|nullable|string|max:3000',
            'effective_from' => 'sometimes|nullable|date', 'effective_to' => 'sometimes|nullable|date',
            'reason' => 'required|string|min:5|max:1000',
        ]);
    }

    /** What the rank engine does with withdrawals, redeemed capital and reinvestments TODAY (read from RankInvestmentLedger). */
    protected function calculationPolicy(): array
    {
        return [
            'confirmed_by_management' => false,
            'summary' => 'The framework requires SSI to confirm how withdrawals, redeemed capital, reinvestments and completed cycles affect rank before production activation. This is what the engine does today; it has NOT been confirmed by management.',
            'rules' => [
                ['topic' => 'Withdrawals', 'behaviour' => 'Ignored. The engine never reads the withdrawals table; a withdrawal does not reduce the qualifying amount or remove a completed cycle.'],
                ['topic' => 'Redeemed capital', 'behaviour' => 'Ignored once the investment has been recorded and verified. A completed investment keeps counting at its verified USD value; capital paid back to the wallet does not subtract from it.'],
                ['topic' => 'Reinvestment', 'behaviour' => 'Each separate, genuine investment record is its own investment and, once completed, its own cycle. Editing or extending the SAME record never adds a cycle. No "reinvestment" marker exists in the data.'],
                ['topic' => 'Completed cycle', 'behaviour' => 'Status is completed, the amount is above zero, and both the recorded end date and the plan-scheduled end have been reached.'],
                ['topic' => 'Excluded', 'behaviour' => 'Cancelled investments, possible duplicates, records without a verified USD valuation, and deposits that were never invested.'],
            ],
        ];
    }

    protected function accountId(int $id): string
    {
        return config('ranks.account_prefix', 'INVSTR-') . str_pad((string) $id, (int) config('ranks.account_pad', 5), '0', STR_PAD_LEFT);
    }

    /** Neutralise spreadsheet formula injection in exported text. */
    protected function safe($v): string
    {
        $s = (string) ($v ?? '');
        return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $s : $s;
    }
}
