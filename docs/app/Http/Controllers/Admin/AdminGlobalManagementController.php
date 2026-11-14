<?php
// LOCATION: app/Http/Controllers/Admin/AdminGlobalManagementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\BalanceAdjustment;
use App\Models\ProfitAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGlobalManagementController extends Controller
{
    use \App\Http\Controllers\Concerns\ReadsIdempotencyKey;
    use \App\Http\Controllers\Concerns\RespondsWithApproval;

    // ── PROFIT RATE ADJUSTMENT ────────────────────────────────────────────
    // Adjusts the profit_percentage (and recalculates expected_profit / total_return)
    // on currently active investments, scoped globally, by sector, by plan, or by specific users.

    // POST /admin/global/profit-adjustments
    public function adjustProfit(Request $request)
    {
        $validated = $request->validate([
            'scope'               => ['required', 'in:global,sector,plan,users'],
            'sector_id'           => ['required_if:scope,sector', 'nullable', 'exists:sectors,id'],
            'investment_plan_id'  => ['required_if:scope,plan', 'nullable', 'exists:investment_plans,id'],
            'user_ids'            => ['required_if:scope,users', 'nullable', 'array'],
            'user_ids.*'          => ['exists:users,id'],
            'percentage_change'   => ['required', 'numeric', 'between:-100,100'],
            'reason'              => ['required', 'string', 'min:10', 'max:1000'],
            // F-04: changing an EXISTING contractual return needs documented authorisation (e.g. Board minute / ticket reference).
            'authorization_reference' => ['required', 'string', 'min:3', 'max:255'],
            'preview'             => ['sometimes', 'boolean'],
        ]);

        $profits = app(\App\Services\Financial\ProfitAdjustmentService::class);
        $payload = collect($validated)->except('preview')->all();

        // PREVIEW: shows every investment that would change (before → after). No writes.
        if ($request->boolean('preview')) {
            return response()->json(['preview' => true] + $profits->preview($payload));
        }

        // Second approver required by default (config financial_controls.require_approval.profit_adjust). Nothing changes until it is approved.
        if (config('financial_controls.require_approval.profit_adjust', true)) {
            $o = app(\App\Services\Financial\FinancialApprovalService::class)
                ->submitDetailed('profit_adjust', $payload, $request->user(), $this->idempotencyKeyFrom($request));
            return $this->approvalResponse($o['request'], $o['duplicate'], 'Profit adjustment submitted for second approval. NO investment has been changed.');
        }

        try {
            $adjustment = $profits->apply($payload, $request->user(), null, null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'message' => "Profit rate adjusted on {$adjustment->affected_count} active investment(s).",
            'adjustment' => $adjustment,
        ]);
    }

    // POST /admin/global/profit-adjustments/{adjustment}/reverse — restores recorded before-values (second approval applies).
    public function reverseProfit(Request $request, ProfitAdjustment $adjustment)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $payload = ['profit_adjustment_id' => $adjustment->id, 'reason' => $validated['reason']];

        if (config('financial_controls.require_approval.profit_adjust', true)) {
            $o = app(\App\Services\Financial\FinancialApprovalService::class)
                ->submitDetailed('profit_reverse', $payload, $request->user(), $this->idempotencyKeyFrom($request));
            return $this->approvalResponse($o['request'], $o['duplicate'], 'Reversal submitted for second approval. NO investment has been changed.');
        }
        try {
            $r = app(\App\Services\Financial\ProfitAdjustmentService::class)->reverse($adjustment->id, $validated['reason'], $request->user(), null, null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        return response()->json(['message' => 'Profit adjustment reversed.'] + $r);
    }

    // GET /admin/global/profit-adjustments/{adjustment}/items — per-investment before/after record
    public function profitAdjustmentItems(Request $request, ProfitAdjustment $adjustment)
    {
        return response()->json(['items' => \App\Models\ProfitAdjustmentItem::where('profit_adjustment_id', $adjustment->id)->orderBy('investment_id')->get()]);
    }

    // GET /admin/global/profit-adjustments
    public function profitAdjustmentHistory(Request $request)
    {
        // Open (pending) reversal requests, keyed by adjustment id, so the UI can show "reversal pending" instead of offering a second reversal.
        $pendingReversal = \App\Models\FinancialApprovalRequest::where('operation', 'profit_reverse')->where('status', \App\Models\FinancialApprovalRequest::PENDING)
            ->get(['id', 'payload'])->mapWithKeys(fn ($r) => [(int) ($r->payload['profit_adjustment_id'] ?? 0) => $r->id]);

        $history = ProfitAdjustment::with(['admin:id,name', 'sector:id,name', 'investmentPlan:id,name'])
            ->latest()
            ->take(50)
            ->get()
            ->map(fn($a) => [
                'id'                 => $a->id,
                'status'             => $a->status ?? 'applied',
                'authorization_reference' => $a->authorization_reference,
                'reversed_at'        => $a->reversed_at?->toDateTimeString(),
                'reversal_reason'    => $a->reversal_reason,
                'reversal_pending_request_id' => $pendingReversal[$a->id] ?? null,
                'scope'              => $a->scope,
                'sector_name'        => $a->sector->name ?? null,
                'plan_name'          => $a->investmentPlan->name ?? null,
                'percentage_change'  => (float) $a->percentage_change,
                'affected_count'     => $a->affected_count,
                'reason'             => $a->reason,
                'admin_name'         => $a->admin->name ?? 'System',
                'created_at'         => $a->created_at->toDateTimeString(),
            ]);

        return response()->json(['history' => $history]);
    }

    // ── MASS BALANCE OPERATIONS ───────────────────────────────────────────
    // Credits or deducts many investors' balances at once, scoped to everyone,
    // a hand-picked list, or everyone with an active investment in a sector.

    // POST /admin/global/balance-bulk
    public function bulkBalance(Request $request)
    {
        $validated = $request->validate([
            'scope'       => ['required', 'in:all,selected,sector'],
            'user_ids'    => ['required_if:scope,selected', 'nullable', 'array'],
            'user_ids.*'  => ['exists:users,id'],
            'sector_id'   => ['required_if:scope,sector', 'nullable', 'exists:sectors,id'],
            'type'        => ['required', 'in:add,deduct'],
            'amount_type' => ['required', 'in:fixed,percentage'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'reason'      => ['required', 'string', 'min:5', 'max:500'],
            'preview'     => ['sometimes', 'boolean'],
        ]);
        $payload = collect($validated)->except('preview')->all();
        $svc = app(\App\Services\Financial\BalanceAdjustmentService::class);

        // F-03: PREVIEW before execution — no writes.
        if ($request->boolean('preview')) {
            return response()->json(['preview' => true] + $svc->previewBulk($payload));
        }

        // F-03/F-14: bulk balance changes need a second approver (config). Nothing changes until approved by a DIFFERENT authorised person.
        if (config('financial_controls.dual_approval_enabled', true) && config('financial_controls.require_approval.bulk_balance', true)) {
            $summary = $svc->previewBulk($payload);
            $o = app(\App\Services\Financial\FinancialApprovalService::class)
                ->submitDetailed('bulk_balance', $payload, $request->user(), $this->idempotencyKeyFrom($request));
            return $this->approvalResponse($o['request'], $o['duplicate'], 'Bulk balance change submitted for second approval. NO balance has been changed.',
                ['preview' => ['count' => $summary['count'], 'will_change' => $summary['will_change'], 'total_delta' => $summary['total_delta']]]);
        }

        try {
            $r = $svc->applyBulk($payload, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
        $skipped = count($r['skipped']);
        return response()->json([
            'message'  => "Balance {$validated['type']} applied to {$r['applied']} investor(s)."
                          . ($skipped > 0 ? " {$skipped} skipped (see skipped_accounts)." : ''),
            'affected' => $r['applied'],
            'skipped'  => $skipped,
            'skipped_accounts' => $r['skipped'],
            'total_delta' => $r['total_delta'],
        ]);
    }
}
