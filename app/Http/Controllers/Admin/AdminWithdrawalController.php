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
use Illuminate\Support\Facades\DB;

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
    //
    // Same reasoning as AdminDepositController::approve() — Admin and
    // Financial dashboards share this method, so the status check and the
    // balance debit are locked together in one transaction. This also
    // fixes a second race: the insufficient-balance check now happens
    // against a row-locked user balance, so two concurrent withdrawal
    // approvals for the same investor can't both pass the balance check
    // and jointly overdraw the account.
    public function approve(Request $request, Withdrawal $withdrawal)
    {
        $outcome = DB::transaction(function () use ($request, $withdrawal) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if (!in_array($locked->status, ['pending', 'hold'], true)) {
                return ['ok' => false, 'reason' => 'not_pending', 'user' => null];
            }

            $user = User::where('id', $locked->user_id)->lockForUpdate()->first();
            if ($user && $user->balance < $locked->amount) {
                return ['ok' => false, 'reason' => 'insufficient', 'user' => null];
            }

            $locked->update([
                'status'       => 'approved',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            if ($user) {
                $balanceBefore = (float) ($user->balance ?? 0);
                $user->decrement('balance', $locked->amount);
                $balanceAfter = (float) $user->balance;

                BalanceAdjustment::create([
                    'user_id'        => $user->id,
                    'admin_id'       => $request->user()->id,
                    'type'           => 'deduct',
                    'amount'         => $locked->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'reason'         => "Withdrawal #{$locked->id} approved",
                ]);
            }

            return ['ok' => true, 'user' => $user, 'amount' => (float) $locked->amount];
        });

        if (!$outcome['ok']) {
            $message = $outcome['reason'] === 'insufficient' ? 'Insufficient user balance.' : 'Withdrawal is not pending or on hold.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }
            return back()->withErrors(['error' => $outcome['reason'] === 'insufficient' ? 'Insufficient balance.' : 'Not pending.']);
        }

        if ($outcome['user']) {
            $this->telegram->withdrawalApproved($outcome['user']->name ?? $outcome['user']->full_name ?? 'Investor', $outcome['amount']);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal approved.', 'status' => 'approved']);
        }
        return back()->with('success', 'Withdrawal approved.');
    }

    // POST /admin/withdrawals/{withdrawal}/reject
    public function reject(Request $request, Withdrawal $withdrawal)
    {
        $ok = DB::transaction(function () use ($request, $withdrawal) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if (!in_array($locked->status, ['pending', 'hold'], true)) {
                return false;
            }

            $locked->update([
                'status'       => 'rejected',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
                'admin_notes'  => $request->reason ?? $locked->admin_notes,
            ]);

            return true;
        });

        if (!$ok) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Withdrawal is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal rejected.', 'status' => 'rejected']);
        }
        return back()->with('success', 'Withdrawal rejected.');
    }

    // POST /admin/withdrawals/{withdrawal}/hold
    public function hold(Request $request, Withdrawal $withdrawal)
    {
        $ok = DB::transaction(function () use ($withdrawal) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->update([
                'status'  => 'hold',
                'held_at' => now(),
            ]);

            return true;
        });

        if (!$ok) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Only pending withdrawals can be put on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

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