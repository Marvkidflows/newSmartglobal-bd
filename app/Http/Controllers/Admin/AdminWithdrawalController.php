<?php
// LOCATION: app/Http/Controllers/Admin/AdminWithdrawalController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\User;
use App\Models\InvestmentAccount;
use App\Models\BalanceAdjustment;
use App\Services\TelegramService;
use App\Services\FinancialAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWithdrawalController extends Controller
{
    protected TelegramService $telegram;
    protected FinancialAuditService $audit;

    public function __construct(TelegramService $telegram, FinancialAuditService $audit)
    {
        $this->telegram = $telegram;
        $this->audit    = $audit;
    }

    // GET /admin/withdrawals
    public function index(Request $request)
    {
        $query = Withdrawal::with(['user:id,name,email,investor_rank_id', 'user.investorRank'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $withdrawals = $query->get()->map(fn($w) => [
            'id'              => $w->id,
            'amount'          => (float) $w->amount,
            // Task 5 (additive): fee disclosure frozen at submission
            'gross_amount' => (string) ($w->gross_amount ?? $w->amount), 'fee_amount' => (string) ($w->fee_amount ?? '0.00'), 'net_amount' => (string) ($w->net_amount ?? $w->amount),
            'fee_status' => $w->fee_status ?: 'none', 'transaction_reference' => $w->transaction_reference,
            'method'          => $w->method,
            'account_details' => $w->account_details_array,
            'status'          => $w->status,
            'admin_notes'     => $w->admin_notes,
            'created_at'      => $w->created_at->toDateString(),
            'user' => [
                'id'    => $w->user->id ?? null,
                'name'  => $w->user->name ?? 'Unknown',
                'email' => $w->user->email ?? '',
                'rank'  => $w->user?->rankBadge(),
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
            'gross_amount' => (string) ($withdrawal->gross_amount ?? $withdrawal->amount), 'fee_amount' => (string) ($withdrawal->fee_amount ?? '0.00'), 'net_amount' => (string) ($withdrawal->net_amount ?? $withdrawal->amount),
            'fee_status' => $withdrawal->fee_status ?: 'none', 'transaction_reference' => $withdrawal->transaction_reference, 'fee_explanation' => $withdrawal->fee_calculation['explanation'] ?? null,
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

            // D-06: a vanished withdrawal row, or a non-pending one, is refused before anything is written.
            if (!$locked || !in_array($locked->status, ['pending', 'hold'], true)) {
                return ['ok' => false, 'reason' => 'not_pending', 'user' => null];
            }

            // D-06: FAIL CLOSED when the investor row is missing. It used to approve the withdrawal and skip the debit entirely
            // (an approved payout that never reduced any balance). The row lock plus the conditional UPDATE below mean two approvals can
            // never jointly overdraw, even if another writer ignores the lock (mirrors BalanceAdjustmentService).
            $user = User::where('id', $locked->user_id)->lockForUpdate()->first();
            if (!$user) {
                return ['ok' => false, 'reason' => 'missing_user', 'user' => null];
            }
            $balanceBefore = (float) ($user->balance ?? 0);
            if ($balanceBefore < (float) $locked->amount) {
                return ['ok' => false, 'reason' => 'insufficient', 'user' => null];
            }
            $debited = User::where('id', $user->id)->where('balance', '>=', $locked->amount)->decrement('balance', $locked->amount);
            if ($debited !== 1) {
                return ['ok' => false, 'reason' => 'insufficient', 'user' => null];   // nothing has been written yet
            }
            $user->refresh();

            $prevStatus = $locked->status;
            $locked->update([
                'status'       => 'approved',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            // Audit trail — same transaction as the approval itself.
            $this->audit->record(
                $request->user(), 'withdrawal.approved', 'withdrawal', $locked->id, $locked->user_id,
                ['status' => $prevStatus], ['status' => 'approved', 'amount' => (float) $locked->amount],
                $request->input('reason')
            );

            {   // the debit itself already happened above, conditionally
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

                // Financial Team §1B/§2: the completed payout is recorded, and — only now that it is approved and processed — the PRINCIPAL
                // components classified for it reduce their positions. Pending/rejected/on-hold withdrawals never reach this point. A profit
                // component reduces nothing. A duplicate deduction aborts the whole approval.
                app(\App\Services\Ledger\WalletLedger::class)->record([
                    'entry_key' => "withdrawal:{$locked->id}:payout", 'user_id' => $user->id, 'classification' => 'completed_payout', 'direction' => 'debit',
                    'amount' => $locked->amount, 'withdrawal_id' => $locked->id, 'balance_after' => $balanceAfter, 'actor_id' => $request->user()->id,
                    'actor_name' => $request->user()->name ?? 'Staff', 'note' => 'Withdrawal approved and processed',
                ]);
                app(\App\Services\Ledger\PrincipalService::class)->applyWithdrawal($locked, $request->user());
            }

            // Task 5: fee accounting INSIDE the same approval transaction (no-op when the withdrawal carries no fee; never collects twice).
            app(\App\Services\Task5\WithdrawalFeeService::class)->onApproved($locked->fresh(), $request->user());

            return ['ok' => true, 'user' => $user, 'amount' => (float) $locked->amount];
        });

        if (!$outcome['ok']) {
            $message = match ($outcome['reason']) {
                'insufficient' => 'Insufficient user balance.',
                'missing_user' => 'The investor account for this withdrawal no longer exists. Nothing was approved or debited.',
                default        => 'Withdrawal is not pending or on hold.',
            };
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

            $prevStatus = $locked->status;
            $locked->update([
                'status'       => 'rejected',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
                'admin_notes'  => $request->reason ?? $locked->admin_notes,
            ]);

            $this->audit->record(
                $request->user(), 'withdrawal.rejected', 'withdrawal', $locked->id, $locked->user_id,
                ['status' => $prevStatus], ['status' => 'rejected', 'amount' => (float) $locked->amount],
                $request->input('reason')
            );

            // Task 5: a rejected withdrawal never debited the wallet, so any disclosed fee is recorded as not collected.
            app(\App\Services\Task5\WithdrawalFeeService::class)->onRejected($locked->fresh(), $request->user());

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
        $ok = DB::transaction(function () use ($request, $withdrawal) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->update([
                'status'  => 'hold',
                'held_at' => now(),
            ]);

            $this->audit->record(
                $request->user(), 'withdrawal.held', 'withdrawal', $locked->id, $locked->user_id,
                ['status' => 'pending'], ['status' => 'hold'], $request->input('reason')
            );

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