<?php
// LOCATION: app/Http/Controllers/Admin/AdminWithdrawalController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\BalanceAdjustment;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    // GET /admin/withdrawals
    public function index(Request $request)
    {
        $query = Withdrawal::with('user:id,name,email')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $withdrawals = $query->get()->map(fn($w) => [
            'id'              => $w->id,
            'amount'          => (float) $w->amount,
            'method'          => $w->method,
            'account_details' => $w->account_details_array,
            'status'          => $w->status,
            'admin_notes'     => $w->admin_notes,
            'created_at'      => $w->created_at->toDateString(),
            'user' => [
                'id'    => $w->user->id ?? null,
                'name'  => $w->user->name ?? 'Unknown',
                'email' => $w->user->email ?? '',
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['withdrawals' => $withdrawals]);
        }
        return view('admin.withdrawals.index', compact('withdrawals'));
    }

    // GET /admin/withdrawals/{withdrawal}
    public function show(Request $request, Withdrawal $withdrawal)
    {
        $withdrawal->load('user');
        $user = $withdrawal->user;

        $activePlans = InvestmentAccount::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('investmentPlan:id,name')
            ->get()
            ->map(fn($i) => [
                'id'         => $i->id,
                'plan_name'  => $i->investmentPlan->name ?? 'N/A',
                'amount'     => (float) $i->amount,
                'end_date'   => optional($i->end_date)->toDateString(),
            ]);

        $previousWithdrawals = Withdrawal::where('user_id', $user->id)
            ->where('id', '!=', $withdrawal->id)
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($w) => [
                'id'         => $w->id,
                'amount'     => (float) $w->amount,
                'method'     => $w->method,
                'status'     => $w->status,
                'created_at' => $w->created_at->toDateString(),
            ]);

        $data = [
            'id'              => $withdrawal->id,
            'amount'          => (float) $withdrawal->amount,
            'method'          => $withdrawal->method,
            'account_details' => $withdrawal->account_details_array,
            'status'          => $withdrawal->status,
            'admin_notes'     => $withdrawal->admin_notes,
            'created_at'      => $withdrawal->created_at->toDateString(),
            'processed_at'    => optional($withdrawal->processed_at)->toDateString(),
            'user' => [
                'id'                  => $user->id ?? null,
                'name'                => $user->name ?? 'Unknown',
                'email'               => $user->email ?? '',
                'balance'             => (float) ($user->balance ?? 0),
                'verification_status' => $user->kyc_status_safe ?? 'not_submitted',
            ],
            'active_plans'         => $activePlans,
            'previous_withdrawals' => $previousWithdrawals,
        ];

        if ($request->expectsJson()) {
            return response()->json(['withdrawal' => $data]);
        }
        return view('admin.withdrawals.show', compact('withdrawal'));
    }

    // POST /admin/withdrawals/{withdrawal}/approve
    public function approve(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'hold') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Withdrawal is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

        $user = User::find($withdrawal->user_id);
        if ($user && $user->balance < $withdrawal->amount) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Insufficient user balance.'], 422);
            }
            return back()->withErrors(['error' => 'Insufficient balance.']);
        }

        $withdrawal->update([
            'status'       => 'approved',
            'processed_at' => now(),
            'processed_by' => $request->user()->id,
        ]);

        if ($user) {
            $balanceBefore = (float) ($user->balance ?? 0);
            $user->decrement('balance', $withdrawal->amount);
            $balanceAfter = (float) $user->balance;

            BalanceAdjustment::create([
                'user_id'        => $user->id,
                'admin_id'       => $request->user()->id,
                'type'           => 'deduct',
                'amount'         => $withdrawal->amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reason'         => "Withdrawal #{$withdrawal->id} approved",
            ]);

            $this->telegram->withdrawalApproved($user->name ?? $user->full_name ?? 'Investor', (float) $withdrawal->amount);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal approved.', 'status' => 'approved']);
        }
        return back()->with('success', 'Withdrawal approved.');
    }

    // POST /admin/withdrawals/{withdrawal}/reject
    public function reject(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'hold') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Withdrawal is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

        $withdrawal->update([
            'status'       => 'rejected',
            'processed_at' => now(),
            'processed_by' => $request->user()->id,
            'admin_notes'  => $request->reason ?? $withdrawal->admin_notes,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal rejected.', 'status' => 'rejected']);
        }
        return back()->with('success', 'Withdrawal rejected.');
    }

    // POST /admin/withdrawals/{withdrawal}/hold
    public function hold(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Only pending withdrawals can be put on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

        $withdrawal->update([
            'status'  => 'hold',
            'held_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal placed on hold.', 'status' => 'hold']);
        }
        return back()->with('success', 'Withdrawal on hold.');
    }

    // POST /admin/withdrawals/{withdrawal}/notes
    public function addNote(Request $request, Withdrawal $withdrawal)
    {
        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'max:1000'],
        ]);

        $withdrawal->update(['admin_notes' => $validated['admin_notes']]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Note saved.', 'admin_notes' => $withdrawal->admin_notes]);
        }
        return back()->with('success', 'Note saved.');
    }
}