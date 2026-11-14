<?php
// LOCATION: app/Http/Controllers/Financial/FinancialApprovalController.php
//
// Second-approval inbox for high-risk financial operations (F-03/F-04/F-14/R7/R8). Reachable by the 'financial' middleware group (financial + admin
// roles); the SERVICE then enforces who may decide each operation, that the approver is never the requester, and account status at execution time.
// The `can_*` flags are a UI convenience only — they are recomputed and enforced server-side on every action.
namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\FinancialApprovalRequest as Req;
use App\Models\User;
use App\Services\Financial\FinancialApprovalService;
use App\Support\StaffAccountGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancialApprovalController extends Controller
{
    public function __construct(protected FinancialApprovalService $approvals) {}

    // GET /financial/approvals?status=&operation=&requested_by=&date_from=&date_to=&per_page=&page=
    //   status: a status, a comma list, 'open' (pending+failed), 'stuck' (executing/legacy approved) or 'all'. Default = every status, newest first.
    public function index(Request $request)
    {
        $v = $request->validate([
            'status' => ['nullable', 'string', 'max:100'], 'operation' => ['nullable', 'in:' . implode(',', FinancialApprovalService::OPERATIONS)],
            'requested_by' => ['nullable', 'integer'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $q = Req::with(['requester:id,name,role', 'decider:id,name,role', 'target:id,name,email'])->latest('id');
        $status = $v['status'] ?? null;
        if ($status === 'open') $q->whereIn('status', Req::OPEN);
        elseif ($status === 'stuck') $q->whereIn('status', [Req::EXECUTING, Req::LEGACY_APPROVED]);
        elseif ($status && $status !== 'all') $q->whereIn('status', array_filter(array_map('trim', explode(',', $status))));
        if (!empty($v['operation'])) $q->where('operation', $v['operation']);
        if (!empty($v['requested_by'])) $q->where('requested_by', (int) $v['requested_by']);
        if (!empty($v['date_from'])) $q->where('created_at', '>=', $v['date_from'] . ' 00:00:00');
        if (!empty($v['date_to'])) $q->where('created_at', '<=', $v['date_to'] . ' 23:59:59');

        $page = $q->paginate((int) ($v['per_page'] ?? 50));
        $me = $request->user();
        return response()->json([
            'requests' => collect($page->items())->map(fn ($r) => $this->present($r, $me, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'operations' => FinancialApprovalService::OPERATIONS,
        ]);
    }

    // GET /financial/approvals/{id}   — full detail incl. before/after and the audit history
    public function show(Request $request, int $id)
    {
        $r = Req::with(['requester:id,name,role', 'decider:id,name,role', 'target:id,name,email', 'events.actor:id,name,role'])->find($id);
        if (!$r) return response()->json(['message' => 'Approval request not found.'], 404);
        return response()->json(['request' => $this->present($r, $request->user(), true)]);
    }

    // POST /financial/approvals/{id}/approve
    public function approve(Request $request, int $id)
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        return $this->decide(fn () => $this->approvals->approve($id, $request->user(), $v['note'] ?? null), 'Approved and executed.');
    }

    // POST /financial/approvals/{id}/retry   — re-attempt a FAILED request (all checks repeated; a different authorised person than the requester)
    public function retry(Request $request, int $id)
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        return $this->decide(fn () => $this->approvals->retry($id, $request->user(), $v['note'] ?? null), 'Retried and executed.');
    }

    // POST /financial/approvals/{id}/reject
    public function reject(Request $request, int $id)
    {
        $v = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            $r = $this->approvals->reject($id, $request->user(), $v['note']);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
        return response()->json(['message' => 'Request rejected. Nothing was changed.', 'status' => $r->status]);
    }

    // POST /financial/approvals/{id}/cancel   — requester (or an admin) withdraws a pending/failed request
    public function cancel(Request $request, int $id)
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            $r = $this->approvals->cancel($id, $request->user(), $v['reason']);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
        return response()->json(['message' => 'Request cancelled. Nothing was changed.', 'status' => $r->status]);
    }

    protected function decide(\Closure $run, string $message)
    {
        try {
            $o = $run();
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
        return response()->json(['message' => $message, 'status' => $o['request']->status, 'result' => $o['result']]);
    }

    protected function invalid(ValidationException $e)
    {
        return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
    }

    /** @return array<string,mixed> */
    protected function present(Req $r, User $me, bool $detail): array
    {
        $p = (array) $r->payload;
        $roles = (array) config("financial_controls.approver_roles.{$r->operation}", []);
        $mayDecideRole = StaffAccountGuard::isActiveStaffWithRole($me, $roles) && (int) $r->requested_by !== (int) $me->id;
        $expired = $r->status === Req::PENDING && $r->expires_at && $r->expires_at->isPast();
        $out = [
            'id' => $r->id, 'operation' => $r->operation, 'status' => $expired ? Req::EXPIRED : $r->status, 'is_expired' => (bool) $expired,
            'is_stuck' => in_array($r->status, [Req::EXECUTING, Req::LEGACY_APPROVED], true),
            'reason' => $p['reason'] ?? null, 'authorization_reference' => $r->authorization_reference ?? ($p['authorization_reference'] ?? null),
            'requested_by' => $r->requester?->only(['id', 'name', 'role']), 'decided_by' => $r->decider?->only(['id', 'name', 'role']),
            'target' => $r->target ? $r->target->only(['id', 'name', 'email']) : null,
            'change' => $this->change($r, $p),
            'decision_note' => $r->decision_note, 'last_error' => $r->last_error, 'execution_attempts' => (int) $r->execution_attempts,
            'result' => $r->result,
            'created_at' => $r->created_at?->toDateTimeString(), 'decided_at' => $r->decided_at?->toDateTimeString(), 'executed_at' => $r->executed_at?->toDateTimeString(),
            'expires_at' => $r->expires_at?->toDateTimeString(), 'cancelled_at' => $r->cancelled_at?->toDateTimeString(), 'cancel_reason' => $r->cancel_reason,
            'can_decide' => $r->status === Req::PENDING && !$expired && $mayDecideRole,
            'needs_manual_verification' => (bool) ($r->result['needs_manual_verification'] ?? false),
            'can_retry'  => $r->status === Req::FAILED && $mayDecideRole && !($r->result['needs_manual_verification'] ?? false),
            'can_cancel' => in_array($r->status, Req::OPEN, true) && ((int) $r->requested_by === (int) $me->id || $me->role === 'admin'),
            'is_own'     => (int) $r->requested_by === (int) $me->id,
        ];
        if ($detail) {
            $out['payload'] = $p;
            $out['events'] = $r->events->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'from_status' => $e->from_status, 'to_status' => $e->to_status,
                'note' => $e->note, 'actor' => $e->actor?->only(['id', 'name', 'role']), 'created_at' => $e->created_at?->toDateTimeString()])->values();
        }
        return $out;
    }

    /** A before/after view appropriate to the operation (live balance for wallet changes; recorded original vs proposed for corrections). */
    protected function change(Req $r, array $p): array
    {
        switch ($r->operation) {
            case 'balance_adjust':
                $u = isset($p['user_id']) ? User::find($p['user_id']) : null;
                $cur = $u ? \App\Support\Decimal::money($u->balance) : null;
                $amt = \App\Support\Decimal::money($p['amount'] ?? 0);
                $proj = match ($p['type'] ?? '') {
                    'add' => $cur !== null ? \App\Support\Decimal::add($cur, $amt) : null,
                    'deduct' => $cur !== null ? \App\Support\Decimal::sub($cur, $amt) : null,
                    'reset' => '0.00', default => $cur,
                };
                return ['kind' => 'balance', 'type' => $p['type'] ?? null, 'amount' => $amt, 'current_balance' => $cur, 'projected_balance' => $proj,
                        'note' => 'Current balance is read live; the balance is re-validated again when the request executes.'];
            case 'investment_correction':
                return ['kind' => 'investment', 'investment_id' => $p['investment_id'] ?? null, 'before' => $p['before'] ?? null, 'proposed' => $p['proposed'] ?? null,
                        'after' => $r->result['after'] ?? null];
            case 'profit_adjust':
                return ['kind' => 'profit', 'scope' => $p['scope'] ?? null, 'details' => array_diff_key($p, array_flip(['reason', 'authorization_reference']))];
            case 'bulk_balance':
                return ['kind' => 'bulk', 'details' => array_diff_key($p, array_flip(['reason']))];
            default:
                return ['kind' => $r->operation, 'details' => array_diff_key($p, array_flip(['reason']))];
        }
    }
}
