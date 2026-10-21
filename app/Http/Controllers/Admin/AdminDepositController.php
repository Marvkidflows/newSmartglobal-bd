<?php
// LOCATION: app/Http/Controllers/Admin/AdminDepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\User;
use App\Models\BalanceAdjustment;
use App\Services\TelegramService;
use App\Services\FinancialAuditService;
use App\Services\DepositPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDepositController extends Controller
{
    protected TelegramService $telegram;
    protected FinancialAuditService $audit;

    protected DepositPaymentService $payments;

    public function __construct(TelegramService $telegram, FinancialAuditService $audit, DepositPaymentService $payments)
    {
        $this->telegram = $telegram;
        $this->audit    = $audit;
        $this->payments = $payments;
    }

    /** What was actually paid (staff-verified or only the investor's declaration). Null when nothing was recorded. */
    public static function paymentBlock(Deposit $d): ?array
    {
        if ($d->paid_currency === null && $d->paid_amount === null && $d->external_reference === null && $d->funding_channel === null) return null;

        return [
            'paid_amount' => $d->paid_amount !== null ? (float) $d->paid_amount : null,
            'paid_currency' => $d->paid_currency,
            'fx_rate' => $d->fx_rate !== null ? (float) $d->fx_rate : null,
            'rate_source' => $d->rate_source, 'rate_date' => $d->rate_date?->toDateString(), 'payment_date' => $d->payment_date?->toDateString(),
            'approved_amount' => $d->approved_amount !== null ? (float) $d->approved_amount : null,
            'external_reference' => $d->external_reference, 'funding_channel' => $d->funding_channel,
            'verified' => (bool) $d->payment_verified_at, 'verified_at' => $d->payment_verified_at?->toIso8601String(),
            'verified_by' => $d->payment_verified_by,
        ];
    }

    // GET /admin/deposits
    public function index(Request $request)
    {
        $query = Deposit::with(['user:id,name,email,investor_rank_id', 'user.investorRank'])->latest();

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
            'payment'    => static::paymentBlock($d),
            'user' => [
                'id'    => $d->user->id ?? null,
                'name'  => $d->user->name ?? 'Unknown',
                'email' => $d->user->email ?? '',
                'rank'  => $d->user?->rankBadge(),
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
            'payment'      => static::paymentBlock($deposit),
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
                return ['ok' => false, 'user' => null, 'reason' => 'status'];
            }

            // SAFETY GATE: approval credits the wallet and opens an investment, so the actual payment (amount, currency,
            // and for non-USD the recorded rate / source / payment date) must be verified first. Never assume USD.
            // The one explicit shortcut is the approver attesting — with a reason, under their name — that it was
            // received in USD (only when no other currency was declared).
            if (!$locked->payment_verified_at && $request->boolean('confirm_usd')) {
                $reasonText = trim((string) $request->input('reason'));
                if (mb_strlen($reasonText) < 5) {
                    return ['ok' => false, 'user' => null, 'reason' => 'blocked', 'blockers' => ['Confirming a USD payment needs a reason of at least 5 characters (what you checked).']];
                }
                try {
                    $this->payments->attestUsd($locked, $request->user(), $reasonText);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    return ['ok' => false, 'user' => null, 'reason' => 'blocked', 'blockers' => collect($e->errors())->flatten()->all()];
                }
                $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();
            }
            $blockers = $this->payments->approvalBlockers($locked);
            if ($blockers) {
                return ['ok' => false, 'user' => null, 'reason' => 'blocked', 'blockers' => $blockers];
            }

            $prevStatus = $locked->status;
            $locked->update([
                'status'       => 'approved',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            // Audit trail — same transaction as the approval itself.
            $this->audit->record(
                $request->user(), 'deposit.approved', 'deposit', $locked->id, $locked->user_id,
                ['status' => $prevStatus], ['status' => 'approved', 'amount' => (float) $locked->amount],
                $request->input('reason')
            );

            $user = User::where('id', $locked->user_id)->lockForUpdate()->first();
            $createdInvestment = null;
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
                if ($locked->investment_plan_id && !\App\Models\InvestmentAccount::where('source_deposit_id', $locked->id)->exists()) {
                    $plan = \App\Models\InvestmentPlan::find($locked->investment_plan_id);

                    if ($plan) {
                        $durationDays = $plan->duration_days ?? ($plan->duration_months ?? 1) * 30;
                        $profitPct    = (float) ($plan->profit_percentage ?? 0);
                        $expectedProfit = $locked->amount * ($profitPct / 100);
                        $totalReturn    = $locked->amount + $expectedProfit;

                        $createdInvestment = \App\Models\InvestmentAccount::create([
                            'source_deposit_id'  => $locked->id,
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

            // Only when SSI personnel verified the actual payment: record the USD value of this deposit and of the
            // investment it opened. Otherwise both stay unverified and are flagged for review (nothing is assumed).
            $this->payments->valueApproved($locked->fresh(), $createdInvestment ?? null, $request->user());

            return ['ok' => true, 'user' => $user, 'amount' => (float) $locked->amount];
        });

        if (!$outcome['ok']) {
            if (($outcome['reason'] ?? null) === 'blocked') {
                $msg = 'This deposit cannot be approved yet: ' . implode(' ', $outcome['blockers']);
                if ($request->expectsJson()) {
                    return response()->json(['message' => $msg, 'code' => 'payment_unverified', 'blockers' => $outcome['blockers']], 422);
                }
                return back()->withErrors(['error' => $msg]);
            }
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

            $prevStatus = $locked->status;
            $locked->update([
                'status'       => 'rejected',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
                'admin_notes'  => $request->reason ?? $locked->admin_notes,
            ]);

            $this->audit->record(
                $request->user(), 'deposit.rejected', 'deposit', $locked->id, $locked->user_id,
                ['status' => $prevStatus], ['status' => 'rejected', 'amount' => (float) $locked->amount],
                $request->input('reason')
            );

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
        $ok = DB::transaction(function () use ($request, $deposit) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->update([
                'status'  => 'hold',
                'held_at' => now(),
            ]);

            $this->audit->record(
                $request->user(), 'deposit.held', 'deposit', $locked->id, $locked->user_id,
                ['status' => 'pending'], ['status' => 'hold'], $request->input('reason')
            );

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

    // POST /admin/deposits/{deposit}/payment-details  (and the Financial twin) — record what was actually paid.
    // Same people and same permission as approve/reject/hold. It does NOT approve anything.
    public function recordPayment(Request $request, Deposit $deposit)
    {
        $v = $request->validate([
            'paid_amount'        => 'required|numeric',
            'paid_currency'      => 'required|string|max:8',
            'rate'               => 'nullable|numeric',
            'rate_source'        => 'nullable|string|max:160',
            'rate_date'          => 'nullable|date',
            'payment_date'       => 'nullable|date',
            'approved_amount'    => 'nullable|numeric',
            'external_reference' => 'nullable|string|max:120',
            'funding_channel'    => 'nullable|in:telegram,app,other',
            'note'               => 'required|string|min:5|max:1000',
            'confirm_distinct'   => 'nullable|boolean',
            'acknowledge_rate_warnings' => 'nullable|boolean',
            'acknowledge_amount_difference' => 'nullable|boolean',
        ]);

        $d = $this->payments->record($deposit, $request->user(), $v, (bool) ($v['confirm_distinct'] ?? false), (bool) ($v['acknowledge_rate_warnings'] ?? false), (bool) ($v['acknowledge_amount_difference'] ?? false));

        return response()->json([
            'message' => sprintf('Recorded: %s %s paid; %s USD to be credited on approval. The deposit is still %s.',
                number_format((float) $d->paid_amount, 2), $d->paid_currency, number_format((float) $d->amount, 2), $d->status),
            'status'  => $d->status,
            'payment' => static::paymentBlock($d),
            'amount'  => (float) $d->amount,
        ]);
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