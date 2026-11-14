<?php
// LOCATION: app/Http/Controllers/Investor/InvestorWithdrawalController.php
//
// TASK 5 CHANGES (everything else is the original behaviour, unchanged: PIN check, balance check, one-pending rule, ledger entry, notifications):
//   * quote():  GET .../withdrawals/quote?amount=  - discloses gross, fee and net BEFORE the investor confirms.
//   * store():  recomputes the fee on the SERVER (the client's numbers are never trusted), requires the investor to echo the disclosed fee/net when a fee
//               applies, freezes the fee configuration on the withdrawal, and runs the pending-check + insert under a row lock on the investor so two
//               simultaneous requests cannot both pass. An optional Idempotency-Key makes a retried POST return the original withdrawal.
//   * index():  adds gross / fee / net / transaction reference / fee status to each row.
//   `amount` keeps meaning "gross requested" and is exactly what approval debits - existing approval, balance and ledger logic is untouched.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Concerns\ReadsIdempotencyKey;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\FinancialNotificationService;
use App\Services\Task5\WithdrawalFeeService;
use App\Services\TelegramService;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class InvestorWithdrawalController extends Controller
{
    use ReadsIdempotencyKey;

    protected TelegramService $telegram;
    protected FinancialNotificationService $financialNotifier;
    protected WithdrawalFeeService $fees;

    public function __construct(TelegramService $telegram, FinancialNotificationService $financialNotifier, WithdrawalFeeService $fees)
    {
        $this->telegram          = $telegram;
        $this->financialNotifier = $financialNotifier;
        $this->fees              = $fees;
    }

    protected function present(Withdrawal $w): array
    {
        $gross = $w->gross_amount ?? $w->amount;
        return [
            'id'                    => $w->id,
            'amount'                => (float) $w->amount,
            'gross_amount'          => Decimal::money($gross),
            'fee_amount'            => Decimal::money($w->fee_amount ?? 0),
            'net_amount'            => Decimal::money($w->net_amount ?? $gross),
            'currency'              => $w->fee_currency ?: 'USD',
            'fee_status'            => $w->fee_status ?: 'none',
            'fee_explanation'       => $w->fee_calculation['explanation'] ?? null,
            'transaction_reference' => $w->transaction_reference,
            'method'                => $w->method,
            'status'                => $w->status,
            'created_at'            => $w->created_at->toDateString(),
            'processed_at'          => optional($w->processed_at)->toDateString(),
        ];
    }

    // GET /investor-investment/investor/withdrawals
    public function index(Request $request)
    {
        $user        = Auth::user();
        $withdrawals = Withdrawal::where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn($w) => $this->present($w) + ['account_details' => $w->account_details_array]);

        if ($request->expectsJson()) {
            return response()->json(['withdrawals' => $withdrawals]);
        }
        return view('investor.withdrawals.index', compact('withdrawals'));
    }

    // GET /investor-investment/investor/withdrawals/create
    public function create(Request $request)
    {
        $user = Auth::user();

        if ($request->expectsJson()) {
            return response()->json([
                'available_balance' => (float) ($user->balance ?? 0),
                'has_pin'           => (bool) $user->withdrawal_pin,
            ]);
        }
        return view('investor.withdrawals.create', compact('user'));
    }

    // GET /investor-investment/investor/withdrawals/quote?amount=100
    public function quote(Request $request)
    {
        $request->validate(['amount' => ['required', 'numeric']]);
        $q = $this->fees->quote($request->query('amount'));          // throws a 422 for zero / negative / invalid / fee >= amount
        $balance = Decimal::money(Auth::user()->balance ?? 0);
        return response()->json(['quote' => [
            'gross' => $q['gross'], 'fee' => $q['fee'], 'net' => $q['net'], 'currency' => $q['currency'], 'fee_type' => $q['fee_type'],
            'fee_applies' => $q['applied'], 'explanation' => $q['explanation'], 'available_balance' => $balance,
            'sufficient_balance' => Decimal::gte($balance, $q['gross']),
        ]]);
    }

    // POST /investor-investment/investor/withdrawals
    public function store(Request $request)
    {
        $user = Auth::user();

        // Withdrawal PIN must exist before any withdrawal can be requested.
        if (!$user->withdrawal_pin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Please set up a withdrawal PIN before requesting a withdrawal.',
                    'code'    => 'PIN_NOT_SET',
                ], 422);
            }
            return back()->withErrors(['error' => 'Please set up a withdrawal PIN first.']);
        }

        $validated = $request->validate([
            'amount'         => ['required', 'numeric', 'min:10'],
            'method'         => ['required', 'string', 'in:bitcoin,ethereum,usdt,bank_transfer'],
            'wallet_address' => ['required_if:method,bitcoin,ethereum,usdt', 'nullable', 'string', 'max:255'],
            'bank_name'      => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:100'],
            'account_number' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:50'],
            'account_name'   => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:100'],
            'withdrawal_pin' => ['required', 'digits:4'],
            // Task 5: the figures the investor was shown and confirmed. Compared with the server's own calculation; never used as the source of truth.
            'confirmed_fee'  => ['nullable', 'numeric'],
            'confirmed_net'  => ['nullable', 'numeric'],
        ]);

        // Verify the submitted PIN
        if (!Hash::check($validated['withdrawal_pin'], $user->withdrawal_pin)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Incorrect withdrawal PIN.',
                    'code'    => 'PIN_INCORRECT',
                ], 422);
            }
            return back()->withErrors(['withdrawal_pin' => 'Incorrect withdrawal PIN.']);
        }

        // Task 5: server-side fee calculation + investor confirmation of exactly those figures.
        $q = $this->fees->quote($validated['amount']);
        $confirmationSupplied = $request->filled('confirmed_fee') || $request->filled('confirmed_net');
        if ($q['applied'] || $confirmationSupplied) {
            $ok = $request->filled('confirmed_fee') && $request->filled('confirmed_net')
                && Decimal::eq(Decimal::money($validated['confirmed_fee']), $q['fee']) && Decimal::eq(Decimal::money($validated['confirmed_net']), $q['net']);
            if (!$ok) {
                return response()->json([
                    'message' => $q['applied'] ? 'Please review and confirm the withdrawal fee and net amount shown before submitting.' : 'The fee shown no longer matches; please review the amounts.',
                    'code'    => 'FEE_CONFIRMATION_REQUIRED',
                    'quote'   => ['gross' => $q['gross'], 'fee' => $q['fee'], 'net' => $q['net'], 'currency' => $q['currency'], 'explanation' => $q['explanation']],
                ], 422);
            }
        }

        $idemKey = $this->idempotencyKeyFrom($request);
        $storedKey = $idemKey ? substr(hash('sha256', $user->id . ':' . $idemKey), 0, 40) : null;

        $accountDetails = $validated['method'] === 'bank_transfer'
            ? [
                'bank_name'      => $validated['bank_name'],
                'account_number' => $validated['account_number'],
                'account_name'   => $validated['account_name'],
              ]
            : [
                'wallet_address' => $validated['wallet_address'],
              ];

        // One transaction, investor row locked: the balance check, the one-pending rule and the insert are atomic per investor.
        $result = DB::transaction(function () use ($user, $q, $validated, $accountDetails, $storedKey) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if ($storedKey && ($dup = Withdrawal::where('idempotency_key', $storedKey)->where('user_id', $user->id)->first())) {
                return ['duplicate' => true, 'withdrawal' => $dup];
            }
            if (Decimal::lt(Decimal::money($locked->balance), $q['gross'])) {
                return ['error' => 'Insufficient balance. Your available balance is $' . number_format($locked->balance, 2), 'status' => 422];
            }
            if (Withdrawal::where('user_id', $user->id)->where('status', 'pending')->exists()) {
                return ['error' => 'You already have a pending withdrawal request. Please wait for it to be processed.', 'status' => 422];
            }

            $withdrawal = new Withdrawal();
            $withdrawal->forceFill([                        // server-built attributes only; nothing fee-related comes from the request
                'user_id' => $user->id, 'method' => $validated['method'], 'account_details' => json_encode($accountDetails), 'status' => 'pending',
                'transaction_reference' => $this->fees->newTransactionReference(), 'idempotency_key' => $storedKey,
            ] + $this->fees->freezeAttributes($q));
            $withdrawal->save();

            $this->fees->recordCharged($withdrawal, $locked);

            // Financial Team §1B/§2D: a pending withdrawal is its own ledger classification and reduces nothing (no cash, no principal).
            app(\App\Services\Ledger\WalletLedger::class)->record([
                'entry_key' => "withdrawal:{$withdrawal->id}:pending", 'user_id' => $user->id, 'classification' => 'pending_withdrawal', 'direction' => 'info',
                'amount' => $q['gross'], 'withdrawal_id' => $withdrawal->id, 'actor_id' => $user->id, 'actor_name' => $user->name ?? 'Investor',
                'note' => 'Withdrawal requested; awaiting approval',
            ]);
            return ['duplicate' => false, 'withdrawal' => $withdrawal];
        });

        if (isset($result['error'])) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $result['error']], $result['status']);
            }
            return back()->withErrors(['amount' => $result['error']]);
        }

        $withdrawal = $result['withdrawal'];

        if (!$result['duplicate']) {
            // Telegram + in-app financial team alerts (unchanged).
            $investorName = $user->name ?? $user->full_name ?? 'Investor';
            $this->telegram->newWithdrawal($investorName, (float) $withdrawal->amount, $withdrawal->method);
            try {
                $this->financialNotifier->newWithdrawal($withdrawal->id, $investorName, (float) $withdrawal->amount);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Financial team withdrawal notification failed: ' . $e->getMessage());
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message'    => $result['duplicate'] ? 'This withdrawal request was already submitted.' : 'Withdrawal request submitted! Awaiting admin approval.',
                'withdrawal' => $this->present($withdrawal->fresh()),
                'duplicate'  => $result['duplicate'],
            ], $result['duplicate'] ? 200 : 201);
        }

        return redirect()->route('investor-investment.withdrawals.index')
            ->with('success', 'Withdrawal request submitted!');
    }
}
