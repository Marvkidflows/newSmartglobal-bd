<?php
// LOCATION: app/Http/Controllers/Admin/AdminUserController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\BalanceAdjustment;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    // GET /admin/users
    public function index(Request $request)
    {
        $query = User::where('role', 'investor');

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
                'name'           => $user->name ?? $user->full_name,
                'email'          => $user->email,
                'phone'          => $user->phone ?? null,
                'country'        => $user->country ?? null,
                'address'        => $user->residential_address ?? null,
                'city'           => $user->city ?? null,
                'balance'        => (float) ($user->balance ?? 0),
                'total_invested' => (float) $investments->sum('amount'),
                'total_profit'   => (float) $investments->sum('expected_profit'),
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

        $user->update($validated);

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
        $user->update(['status' => 'suspended']);

        // Notify investor
        $user->notify(new \App\Notifications\AccountStatusNotification(
            'suspended',
            'Your account has been temporarily suspended by the administration. Please contact support for assistance.'
        ));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Investor suspended.', 'status' => 'suspended']);
        }
        return back()->with('success', 'Investor suspended.');
    }

    // POST /admin/users/{user}/activate
    public function activate(Request $request, User $user)
    {
        $user->update(['status' => 'active']);

        // Notify investor
        $user->notify(new \App\Notifications\AccountStatusNotification(
            'activated',
            'Your account has been reactivated. You can now access all features of the platform.'
        ));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Investor activated.', 'status' => 'active']);
        }
        return back()->with('success', 'Investor activated.');
    }

    // POST /admin/users/{user}/freeze  — NEW
    public function freeze(Request $request, User $user)
    {
        $user->update(['status' => 'frozen']);

        $user->notify(new \App\Notifications\AccountStatusNotification(
            'frozen',
            'Your account has been frozen by the administration. All financial operations have been disabled until further notice.'
        ));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Account frozen.', 'status' => 'frozen']);
        }
        return back()->with('success', 'Account frozen.');
    }

    // POST /admin/users/{user}/unfreeze  — NEW
    public function unfreeze(Request $request, User $user)
    {
        $user->update(['status' => 'active']);

        $user->notify(new \App\Notifications\AccountStatusNotification(
            'unfrozen',
            'Your account freeze has been lifted. Financial operations are now available.'
        ));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Account unfrozen.', 'status' => 'active']);
        }
        return back()->with('success', 'Account unfrozen.');
    }

    // POST /admin/users/{user}/deactivate  — NEW
    public function deactivate(Request $request, User $user)
    {
        // Revoke all tokens so existing sessions are immediately invalidated
        $user->tokens()->delete();
        $user->update(['status' => 'deactivated']);

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

        $balanceBefore = (float) ($user->balance ?? 0);
        $amount        = (float) ($validated['amount'] ?? 0);
        $balanceAfter  = $balanceBefore;

        switch ($validated['type']) {
            case 'add':
                $balanceAfter  = $balanceBefore + $amount;
                $user->balance = $balanceAfter;
                $user->save();
                break;

            case 'deduct':
                if ($amount > $balanceBefore) {
                    return response()->json([
                        'message' => 'Cannot deduct more than the current balance.',
                    ], 422);
                }
                $balanceAfter  = $balanceBefore - $amount;
                $user->balance = $balanceAfter;
                $user->save();
                break;

            case 'freeze':
                $user->status = 'frozen';
                $user->save();
                break;

            case 'unfreeze':
                $user->status = 'active';
                $user->save();
                break;

            case 'reset':
                $balanceAfter  = 0;
                $user->balance = 0;
                $user->save();
                break;
        }

        $adjustment = BalanceAdjustment::create([
            'user_id'        => $user->id,
            'admin_id'       => $request->user()->id,
            'type'           => $validated['type'],
            'amount'         => $amount,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
            'reason'         => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Balance adjustment applied successfully.',
            'user'    => [
                'id'      => $user->id,
                'balance' => (float) $user->balance,
                'status'  => $user->status,
            ],
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
}