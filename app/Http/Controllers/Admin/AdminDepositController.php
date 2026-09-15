<?php
// LOCATION: app/Http/Controllers/Admin/AdminDepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\User;
use App\Models\BalanceAdjustment;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    //
    // Admin and Financial dashboards both hit this exact same method (see
    // routes/api.php). Without row locking, two clicks on the same deposit
    // arriving milliseconds apart (one admin, one financial — or the same
    // person double-clicking) could both read status='pending' before
    // either commits, and both proceed: double balance credit, duplicate
    // BalanceAdjustment rows, duplicate investment activation. Wrapping
    // the check+update in a transaction with lockForUpdate() makes the
    // second request wait for the first to finish, then see the already
    // 'approved' status and cleanly bounce with 409 — no double-processing
    // regardless of which dashboard or team member gets there first.
    public function approve(Request $request, Deposit $deposit)
    {
        $outcome = DB::transaction(function () use ($request, $deposit) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

            if (!in_array($locked->status, ['pending', 'hold'], true)) {
                return ['ok' => false, 'user' => null];
            }

            $locked->update([
                'status'       => 'approved',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            $user = User::where('id', $locked->user_id)->lockForUpdate()->first();
            if ($user) {
                $balanceBefore = (float) ($user->balance ?? 0);
                $user->increment('balance', $locked->amount);
                $balanceAfter = (float) $user->balance;

                BalanceAdjustment::create([
                    'user_id'        => $user->id,
                    'admin_id'       => $request->user()->id,
                    'type'           => 'add',
                    'amount'         => $locked->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'reason'         => "Deposit #{$locked->id} approved",
                ]);

                // Activate the investment if a plan was attached to this deposit
                if ($locked->investment_plan_id) {
                    $plan = \App\Models\InvestmentPlan::find($locked->investment_plan_id);

                    if ($plan) {
                        $durationDays = $plan->duration_days ?? ($plan->duration_months ?? 1) * 30;
                        $profitPct    = (float) ($plan->profit_percentage ?? 0);
                        $expectedProfit = $locked->amount * ($profitPct / 100);
                        $totalReturn    = $locked->amount + $expectedProfit;

                        \App\Models\InvestmentAccount::create([
                            'user_id'            => $user->id,
                            'investment_plan_id' => $plan->id,
                            'amount'             => $locked->amount,
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
            }

            return ['ok' => true, 'user' => $user, 'amount' => (float) $locked->amount];
        });

        if (!$outcome['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Deposit is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Deposit is not pending.']);
        }

        // Telegram call kept outside the transaction on purpose — it's an
        // outbound HTTP call, and doing it while still holding the row
        // locks above would needlessly widen the window for the race this
        // fix exists to close.
        if ($outcome['user']) {
            $this->telegram->depositApproved($outcome['user']->name ?? $outcome['user']->full_name ?? 'Investor', $outcome['amount']);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Deposit approved. Balance credited and investment activated.', 'status' => 'approved']);
        }
        return back()->with('success', 'Deposit approved.');
    }

    // POST /admin/deposits/{deposit}/reject
    public function reject(Request $request, Deposit $deposit)
    {
        $ok = DB::transaction(function () use ($request, $deposit) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

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
                return response()->json(['message' => 'Deposit is not pending or on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Deposit is not pending.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Deposit rejected.', 'status' => 'rejected']);
        }
        return back()->with('success', 'Deposit rejected.');
    }

    // POST /admin/deposits/{deposit}/hold
    public function hold(Request $request, Deposit $deposit)
    {
        $ok = DB::transaction(function () use ($deposit) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

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
                return response()->json(['message' => 'Only pending deposits can be put on hold.'], 422);
            }
            return back()->withErrors(['error' => 'Not pending.']);
        }

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