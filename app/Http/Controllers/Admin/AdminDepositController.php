<?php
// LOCATION: app/Http/Controllers/Admin/AdminDepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\User;
use App\Models\BalanceAdjustment;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class AdminDepositController extends Controller
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    // GET /admin/deposits
    public function index(Request $request)
    {
        $query = Deposit::with('user:id,name,email')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $deposits = $query->get()->map(fn($d) => [
            'id'         => $d->id,
            'amount'     => (float) $d->amount,
            'method'     => $d->payment_method,
            'reference'  => $d->transaction_reference,
            'status'     => $d->status,
            'admin_notes'=> $d->admin_notes,
            'created_at' => $d->created_at->toDateString(),
            'user' => [
                'id'    => $d->user->id ?? null,
                'name'  => $d->user->name ?? 'Unknown',
                'email' => $d->user->email ?? '',
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['deposits' => $deposits]);
        }
        return view('admin.deposits.index', compact('deposits'));
    }

    // GET /admin/deposits/{deposit}
    public function show(Request $request, Deposit $deposit)
    {
        $deposit->load('user');
        $data = [
            'id'           => $deposit->id,
            'amount'       => (float) $deposit->amount,
            'method'       => $deposit->payment_method,
            'reference'    => $deposit->transaction_reference,
            'status'       => $deposit->status,
            'admin_notes'  => $deposit->admin_notes,
            'created_at'   => $deposit->created_at->toDateString(),
            'processed_at' => optional($deposit->processed_at)->toDateString(),
            'user' => [
                'id'    => $deposit->user->id ?? null,
                'name'  => $deposit->user->name ?? 'Unknown',
                'email' => $deposit->user->email ?? '',
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json(['deposit' => $data]);
        }
        return view('admin.deposits.show', compact('deposit'));
    }

    // POST /admin/deposits/{deposit}/approve
    public function approve(Request $request, Deposit $deposit)
    {
        if ($deposit->status !== 'pending' && $deposit->status !== 'hold') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Deposit is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Deposit is not pending.']);
        }

        $deposit->update([
            'status'       => 'approved',
            'processed_at' => now(),
            'processed_by' => $request->user()->id,
        ]);

        $user = User::find($deposit->user_id);
        if ($user) {
            $balanceBefore = (float) ($user->balance ?? 0);
            $user->increment('balance', $deposit->amount);
            $balanceAfter = (float) $user->balance;

            BalanceAdjustment::create([
                'user_id'        => $user->id,
                'admin_id'       => $request->user()->id,
                'type'           => 'add',
                'amount'         => $deposit->amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reason'         => "Deposit #{$deposit->id} approved",
            ]);

            // Activate the investment if a plan was attached to this deposit
            if ($deposit->investment_plan_id) {
                $plan = \App\Models\InvestmentPlan::find($deposit->investment_plan_id);

                if ($plan) {
                    $durationDays = $plan->duration_days ?? ($plan->duration_months ?? 1) * 30;
                    $profitPct    = (float) ($plan->profit_percentage ?? 0);
                    $expectedProfit = $deposit->amount * ($profitPct / 100);
                    $totalReturn    = $deposit->amount + $expectedProfit;

                    \App\Models\InvestmentAccount::create([
                        'user_id'            => $user->id,
                        'investment_plan_id' => $plan->id,
                        'amount'             => $deposit->amount,
                        'profit_percentage'  => $profitPct,
                        'expected_profit'    => $expectedProfit,
                        'total_return'       => $totalReturn,
                        'start_date'         => now()->toDateString(),
                        'end_date'           => now()->addDays($durationDays)->toDateString(),
                        'remaining_days'     => $durationDays,
                        'status'             => 'active',
                    ]);
                }
            }

            $this->telegram->depositApproved($user->name ?? $user->full_name ?? 'Investor', (float) $deposit->amount);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Deposit approved. Balance credited and investment activated.', 'status' => 'approved']);
        }
        return back()->with('success', 'Deposit approved.');
    }

    // POST /admin/deposits/{deposit}/reject
    public function reject(Request $request, Deposit $deposit)
    {
        if ($deposit->status !== 'pending' && $deposit->status !== 'hold') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Deposit is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Deposit is not pending.']);
        }

        $deposit->update([
            'status'       => 'rejected',
            'processed_at' => now(),
            'processed_by' => $request->user()->id,
            'admin_notes'  => $request->reason ?? $deposit->admin_notes,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Deposit rejected.', 'status' => 'rejected']);
        }
        return back()->with('success', 'Deposit rejected.');
    }

    // POST /admin/deposits/{deposit}/hold
    public function hold(Request $request, Deposit $deposit)
    {
        if ($deposit->status !== 'pending') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Only pending deposits can be put on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

        $deposit->update([
            'status'  => 'hold',
            'held_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Deposit placed on hold.', 'status' => 'hold']);
        }
        return back()->with('success', 'Deposit on hold.');
    }

    // POST /admin/deposits/{deposit}/notes
    public function addNote(Request $request, Deposit $deposit)
    {
        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'max:1000'],
        ]);

        $deposit->update(['admin_notes' => $validated['admin_notes']]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Note saved.', 'admin_notes' => $deposit->admin_notes]);
        }
        return back()->with('success', 'Note saved.');
    }
}