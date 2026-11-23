<?php
// LOCATION: app/Http/Controllers/Admin/AdminUserController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\BalanceAdjustment;
use App\Services\FinancialNotificationService;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    use \App\Http\Controllers\Concerns\ReadsIdempotencyKey;
    use \App\Http\Controllers\Concerns\RespondsWithApproval;

    protected FinancialNotificationService $financialNotifier;

    public function __construct(FinancialNotificationService $financialNotifier)
    {
        $this->financialNotifier = $financialNotifier;
    }

    // GET /admin/users
    public function index(Request $request)
    {
        $query = User::where('role', 'investor')->with('investorRank');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")
                                      ->orWhere('email', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->latest()->get()->map(fn($u) => [
            'id'            => $u->id,
            'account_id'    => $u->account_id,
            'rank'          => $u->rankBadge(),
            'name'          => $u->name ?? $u->full_name,
            'email'         => $u->email,
            'phone'         => $u->phone ?? null,
            'country'       => $u->country ?? null,
            'balance'       => (float) ($u->balance ?? 0),
            'status'        => $u->status ?? 'active',
            'role'          => $u->role,
            'last_login_at' => $u->last_login_at ?? null,
            'created_at'    => $u->created_at->toDateString(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['users' => $users]);
        }
        return view('admin.users.index', compact('users'));
    }

    // GET /admin/users/{user}
    public function show(Request $request, User $user)
    {
        $investments = InvestmentAccount::where('user_id', $user->id)
            ->with('investmentPlan')
            ->latest()
            ->get()
            ->map(fn($i) => [
                'id'             => $i->id,
                'plan_name'      => $i->investmentPlan->name ?? 'N/A',
                'amount'         => (float) $i->amount,
                'profit_percent' => $i->investmentPlan->profit_percentage ?? 0,
                'expected_profit'=> (float) ($i->expected_profit ?? 0),
                'status'         => $i->status,
                'start_date'     => optional($i->start_date)->toDateString(),
                'end_date'       => optional($i->end_date)->toDateString(),
                'days_remaining' => $i->remaining_days ?? 0,
                'created_at'     => $i->created_at->toDateString(),
            ]);

        $deposits = Deposit::where('user_id', $user->id)->latest()->get()
            ->map(fn($d) => [
                'id'         => $d->id,
                'amount'     => (float) $d->amount,
                'method'     => $d->payment_method ?? $d->method ?? 'N/A',
                'status'     => $d->status,
                'created_at' => $d->created_at->toDateString(),
            ]);

        $withdrawals = Withdrawal::where('user_id', $user->id)->latest()->get()
            ->map(fn($w) => [
                'id'         => $w->id,
                'amount'     => (float) $w->amount,
                'method'     => $w->method ?? 'N/A',
                'status'     => $w->status,
                'created_at' => $w->created_at->toDateString(),
            ]);

        $balanceHistory = BalanceAdjustment::where('user_id', $user->id)
            ->with('admin:id,name')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'type'           => $b->type,
                'amount'         => (float) $b->amount,
                'balance_before' => (float) $b->balance_before,
                'balance_after'  => (float) $b->balance_after,
                'reason'         => $b->reason,
                'admin_name'     => $b->admin->name ?? 'System',
                'created_at'     => $b->created_at->toDateTimeString(),
            ]);

        $data = [
            'user' => [
                'id'             => $user->id,
                'account_id'     => $user->account_id,
                'rank'           => $user->rankBadge(),
                'name'           => $user->name ?? $user->full_name,
                'email'          => $user->email,
                'phone'          => $user->phone ?? null,
                'country'        => $user->country ?? null,
                'address'        => $user->residential_address ?? null,
                'address_line_2' => $user->address_line_2 ?? null,
                'city'           => $user->city ?? null,
                'state'          => $user->state ?? null,
                'postal_code'    => $user->postal_code ?? null,
                'balance'        => (float) ($user->balance ?? 0),
                'total_invested' => (float) $investments->sum('amount'),
                'total_profit'   => (float) $investments->where('status', 'completed')->sum('expected_profit'),
                'status'         => $user->status ?? 'active',
                'role'           => $user->role,
                'created_at'     => $user->created_at->toDateString(),
                'last_login_at'  => $user->last_login_at ?? null,
            ],
            'investments'     => $investments,
            'deposits'        => $deposits,
            'withdrawals'     => $withdrawals,
            'balance_history' => $balanceHistory,
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('admin.users.show', $data);
    }

    // PUT /admin/users/{user}
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'    => ['sometimes', 'string', 'max:255'],
            'email'   => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'phone'   => ['sometimes', 'string', 'max:20'],
            'status'  => ['sometimes', 'in:active,suspended,inactive,frozen,deactivated'],
            'country' => ['sometimes', 'string', 'max:100'],
        ]);

        $status = \Illuminate\Support\Arr::pull($validated, 'status');   // F-12: status is not mass-assignable
        // D-02: changing someone's sign-in e-mail, or deactivating them, ends their sessions. (If an admin edits their OWN record the current session stays.)
        $endSessions = (isset($validated['email']) && strtolower((string) $validated['email']) !== strtolower((string) $user->email)) || $status === 'deactivated';
        $user->update($validated);
        if ($status !== null) $user->forceFill(['status' => $status])->save();
        if ($endSessions) {
            $request->user()?->is($user) ? \App\Support\AuthTokens::revokeOthers($user) : \App\Support\AuthTokens::revokeAll($user);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Investor updated successfully.',
                'user'    => $user->fresh(),
            ]);
        }
        return back()->with('success', 'Investor updated.');
    }

    // POST /admin/users/{user}/suspend
    public function suspend(Request $request, User $user)
    {
        if ($refuse = $this->refuseSelfLockout($request, $user, 'suspend')) return $refuse;
        $user->forceFill(['status' => 'suspended'])->save();

        // The suspension is committed above. Notifying the investor is a separate, best-effort step: a delivery failure (for example Brevo being
        // unreachable) must neither undo the suspension nor make the administrator believe it failed. It is logged (securely) and reported in the response.
        $notified = $this->notifyAccountStatus(
            $request,
            $user,
            'suspended',
            'Your account has been temporarily suspended by the administration. Please contact support for assistance.'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message'                => $notified ? 'Investor suspended.' : 'Investor suspended, but the notification could not be delivered to the investor.',
                'status'                 => 'suspended',
                'notification_delivered' => $notified,
            ]);
        }
        $response = back()->with('success', 'Investor suspended.');
        return $notified ? $response : $response->with('warning', 'The notification could not be delivered to the investor.');
    }

    /**
     * Send the account-status notification AFTER the status change has been saved. Never throws.
     * Channels run in the order of AccountStatusNotification::via() — 'database' first, then 'mail' — so when only the mail provider fails, the
     * in-app (database) notification has already been stored.
     * @return bool true when the notification was handed to every channel, false when any delivery failed (logged, never swallowed silently)
     */
    private function notifyAccountStatus(Request $request, User $user, string $action, string $message): bool
    {
        try {
            $user->notify(new \App\Notifications\AccountStatusNotification($action, $message));
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Account status notification could not be delivered; the status change itself was saved.', [
                'user_id'   => $user->id,
                'action'    => $action,
                'admin_id'  => $request->user()?->id,
                'exception' => get_class($e),
                'error'     => $this->redactNotificationError($e->getMessage()),   // no trace, no recipient address, no credentials
            ]);
            return false;
        }
    }

    /** Strip anything credential- or person-shaped from a provider error before it reaches the log. */
    private function redactNotificationError(string $message): string
    {
        $message = preg_replace('/\b(api[-_ ]?key|token|secret|password|authorization|bearer)\b\s*[=:]?\s*\S+/i', '$1 [redacted]', $message);
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $message);
        $message = preg_replace('/[A-Za-z0-9_\-\.]{24,}/', '[redacted]', $message);
        return mb_substr($message, 0, 300);
    }

    // POST /admin/users/{user}/activate
    public function activate(Request $request, User $user)
    {
        // A freeze is lifted only through Unfreeze (audited, approval-aware). Activate must not silently bypass it.
        if (\App\Support\StaffAccountGuard::normalisedStatus($user) === 'frozen') {
            return response()->json(['message' => 'This account is frozen. Use Unfreeze (a reason is recorded in the audit log); Activate is for suspended or deactivated accounts.'], 422);
        }
        $user->forceFill(['status' => 'active'])->save();

        // Notify investor
        // Best-effort: the status change is already saved; a mail/provider failure must not turn it into a 500.
        $this->notifyAccountStatus($request, $user, 'activated', 'Your account has been reactivated. You can now access all features of the platform.');

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Investor activated.', 'status' => 'active']);
        }
        return back()->with('success', 'Investor activated.');
    }

    // POST /admin/users/{user}/freeze  — NEW
    public function freeze(Request $request, User $user)
    {
        if ($refuse = $this->refuseSelfLockout($request, $user, 'freeze')) return $refuse;
        $user->forceFill(['status' => 'frozen'])->save();

        // Best-effort: the status change is already saved; a mail/provider failure must not turn it into a 500.
        $this->notifyAccountStatus($request, $user, 'frozen', 'Your account has been frozen by the administration. All financial operations have been disabled until further notice.');

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Account frozen.', 'status' => 'frozen']);
        }
        return back()->with('success', 'Account frozen.');
    }

    // POST /admin/users/{user}/unfreeze  — NEW
    public function unfreeze(Request $request, User $user)
    {
        // R9: lifting a freeze must never reactivate a suspended / deactivated account — that is what activate() is for, deliberately.
        if (\App\Support\StaffAccountGuard::normalisedStatus($user) !== 'frozen') {
            return response()->json(['message' => 'Only a frozen account can be unfrozen. Use activate to reinstate a suspended or deactivated account.'], 422);
        }
        $user->forceFill(['status' => 'active'])->save();

        // Best-effort: the status change is already saved; a mail/provider failure must not turn it into a 500.
        $this->notifyAccountStatus($request, $user, 'unfrozen', 'Your account freeze has been lifted. Financial operations are now available.');

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Account unfrozen.', 'status' => 'active']);
        }
        return back()->with('success', 'Account unfrozen.');
    }

    // POST /admin/users/{user}/deactivate  — NEW
    public function deactivate(Request $request, User $user)
    {
        if ($refuse = $this->refuseSelfLockout($request, $user, 'deactivate')) return $refuse;
        // Revoke all tokens so existing sessions are immediately invalidated
        $user->tokens()->delete();
        $user->forceFill(['status' => 'deactivated'])->save();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Account deactivated.', 'status' => 'deactivated']);
        }
        return back()->with('success', 'Account deactivated.');
    }

    // POST /admin/users/{user}/balance
    public function adjustBalance(Request $request, User $user)
    {
        $validated = $request->validate([
            'type'   => ['required', 'in:add,deduct,freeze,unfreeze,reset'],
            'amount' => ['required_if:type,add,deduct', 'nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // F-03 / F-14 / R3: one policy entry point (self-adjustment refused, locked deduction check BEFORE queuing, single + cumulative thresholds,
        // second approver for resets and large/split adjustments). Nothing is debited at submission.
        $svc = app(\App\Services\Financial\BalanceAdjustmentService::class);
        try {
            $o = $svc->submitOrApply($request->user(), $user->id, $validated['type'], $validated['amount'] ?? null, $validated['reason'], $this->idempotencyKeyFrom($request), false);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
        if ($o['mode'] === 'pending') {
            return $this->approvalResponse($o['request'], $o['duplicate'], 'This adjustment requires a second approver. It has been submitted for approval and NO balance has been changed.');
        }
        $adjustment = $o['adjustment']; $user = $o['user'];

        if (!$o['duplicate']) {
            // The financial team is told whenever an admin adds, deducts, or resets an investor's balance.
            $this->financialNotifier->balanceAdjusted(
                $user->id, $user->name ?? $user->full_name ?? 'Investor', $validated['type'], (float) $adjustment->amount, $request->user()->name
            );
        }

        return response()->json([
            'message' => $o['duplicate'] ? 'Duplicate submission ignored; the original adjustment is returned.' : 'Balance adjustment applied successfully.',
            'duplicate' => $o['duplicate'],
            'user'    => ['id' => $user->id, 'balance' => (float) $user->balance, 'status' => $user->status],
            'adjustment' => [
                'id'             => $adjustment->id,
                'type'           => $adjustment->type,
                'amount'         => (float) $adjustment->amount,
                'balance_before' => (float) $adjustment->balance_before,
                'balance_after'  => (float) $adjustment->balance_after,
                'reason'         => $adjustment->reason,
                'admin_name'     => $request->user()->name,
                'created_at'     => $adjustment->created_at->toDateTimeString(),
            ],
        ]);
    }

    /** R9: staff are locked out of everything when not 'active', so nobody may suspend / freeze / deactivate THEIR OWN account (self-lockout of the last admin). */
    protected function refuseSelfLockout(Request $request, User $user, string $verb)
    {
        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json(['message' => "You cannot {$verb} your own account. Ask another administrator."], 422);
        }
        return null;
    }
}