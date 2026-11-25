<?php
// LOCATION: app/Http/Controllers/Investor/InvestorDepositController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\InvestmentPlan;
use App\Models\Message;
use App\Services\FinancialConversationService;
use App\Services\TelegramService;
use App\Services\DepositPaymentService;
use App\Services\DepositAgentResolver;
use App\Services\Rank\CurrencyCatalog;
use App\Services\FinancialNotificationService;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvestorDepositController extends Controller
{
    protected TelegramService $telegram;
    protected FinancialNotificationService $financialNotifier;

    public function __construct(TelegramService $telegram, FinancialNotificationService $financialNotifier)
    {
        $this->telegram          = $telegram;
        $this->financialNotifier = $financialNotifier;
    }

    // GET /investor-investment/investor/deposits
    public function index(Request $request)
    {
        $user     = Auth::user();
        $deposits = Deposit::where('user_id', $user->id)
            ->with('investmentPlan:id,name')
            ->latest()
            ->get()
            ->map(fn($d) => [
                'id'          => $d->id,
                'amount'      => (float) $d->amount,
                'method'      => $d->payment_method,
                'reference'   => $d->transaction_reference,
                'plan_name'   => $d->investmentPlan->name ?? null,
                'status'      => $d->status,
                'created_at'  => $d->created_at->toDateString(),
            ]);

        if ($request->expectsJson()) {
            return response()->json(['deposits' => $deposits]);
        }
        return view('investor.deposits.index', compact('deposits'));
    }

    // GET /investor-investment/investor/deposits/currencies — currency codes the investor may state for their payment
    public function currencies()
    {
        return response()->json(['currencies' => collect(app(CurrencyCatalog::class)->all())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()]);
    }

    // GET /investor-investment/investor/deposits/create
    public function create(Request $request)
    {
        $plans = InvestmentPlan::where('status', 'active')
            ->orderBy('min_amount')
            ->get(['id', 'name', 'min_amount', 'max_amount', 'profit_percentage', 'duration_days']);

        if ($request->expectsJson()) {
            return response()->json(['plans' => $plans, 'agent' => $this->agentPayload(Auth::user())]);
        }
        return view('investor.deposits.create', compact('plans'));
    }

    // POST /investor-investment/investor/deposits/initiate
    // The investor's submission. Saves a PENDING deposit (nothing is paid, approved, credited or invested here), resolves the
    // investor's OWN Telegram contact server-side, notifies staff, and returns what the frontend needs to open Telegram.
    // Idempotent: the same `idempotency_key` from the same investor always returns the same deposit and never notifies twice.
    public function initiate(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'investment_plan_id' => ['required', 'exists:investment_plans,id'],
            'amount'             => ['required', 'numeric', 'min:1'],
            'idempotency_key'    => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);
        $key = $validated['idempotency_key'] ?? null;

        // A retry of an already-saved submission: return it, do nothing else.
        if ($key && ($existing = $this->findByKey($user->id, $key))) {
            return $this->depositResponse($existing, $user, true);
        }

        $plan = InvestmentPlan::findOrFail($validated['investment_plan_id']);

        if ($validated['amount'] < $plan->min_amount) {
            return response()->json([
                'message' => "Minimum amount for {$plan->name} is $" . number_format($plan->min_amount, 2),
            ], 422);
        }

        if ($plan->max_amount && $validated['amount'] > $plan->max_amount) {
            return response()->json([
                'message' => "Maximum amount for {$plan->name} is $" . number_format($plan->max_amount, 2),
            ], 422);
        }

        $reference = 'DEP-' . now()->year . '-' . strtoupper(Str::random(8));

        try {
            // Deposit + its pending ledger entry are saved together, so a deposit is never left without its audit entry.
            $deposit = DB::transaction(function () use ($user, $plan, $validated, $reference, $key) {
                $deposit = Deposit::create([
                    'user_id'               => $user->id,
                    'investment_plan_id'    => $plan->id,
                    'amount'                => $validated['amount'],
                    'payment_method'        => 'pending', // updated at the confirm step
                    'transaction_reference' => $reference,
                    'status'                => 'pending', // ALWAYS pending here; only the existing approval flow changes it
                    'idempotency_key'       => $key,
                ]);

                // Financial Team §1B: a declared, not-yet-cleared deposit is its own ledger classification (no money has moved).
                app(\App\Services\Ledger\WalletLedger::class)->record([
                    'entry_key' => "deposit:{$deposit->id}:pending", 'user_id' => $user->id, 'classification' => 'pending_deposit', 'direction' => 'info',
                    'amount' => $validated['amount'], 'deposit_id' => $deposit->id, 'reference' => $reference, 'actor_id' => $user->id, 'actor_name' => $user->name ?? 'Investor',
                    'note' => 'Deposit declared; awaiting verification of receipt and clearance',
                ]);

                return $deposit;
            });
        } catch (QueryException $e) {
            // Two identical submissions raced: the UNIQUE (user_id, idempotency_key) index let only one through.
            if ($key && ($existing = $this->findByKey($user->id, $key))) {
                return $this->depositResponse($existing, $user, true);
            }
            throw $e;
        }

        // ── From here the deposit is saved. Nothing below may turn it into an error for the investor. ──
        $name = $this->investorName($user);

        try {
            $this->telegram->newDeposit($name, (float) $deposit->amount, $reference);   // existing staff-chat alert (fails silently)
        } catch (\Throwable $e) {
            Log::warning('Staff Telegram deposit alert failed: ' . get_class($e));
        }

        // In-app Financial Team alert (no internal try/catch, e.g. notifications table not migrated yet).
        try {
            $this->financialNotifier->newDeposit($deposit->id, $name, (float) $deposit->amount);
        } catch (\Throwable $e) {
            Log::warning('Financial team deposit notification failed: ' . $e->getMessage());
        }

        $notified = $this->notifyAssignedAgent($deposit, $user, $plan->name ?? 'Plan', $name);

        // Deposit enquiry -> the EXISTING in-dashboard Financial Team conversation (no Telegram needed). Same guarantee as above:
        // the deposit is saved, so a problem here is logged and never becomes an error for the investor.
        $this->openDepositEnquiry($deposit, $user, $plan->name ?? 'Plan');

        return $this->depositResponse($deposit->fresh() ?? $deposit, $user, false, $notified, $plan->name);
    }

    // PUT /investor-investment/investor/deposits/{deposit}/confirm
    // Called at Stage 3 — investor confirms payment method + uploads proof
    public function confirm(Request $request, Deposit $deposit)
    {
        if ($deposit->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:bitcoin,ethereum,usdt,bank_transfer'],
            'screenshot'     => ['nullable', 'image', 'max:5120'],
            // OPTIONAL declaration of how the investor paid. Unverified until SSI personnel record the actual payment;
            // it never changes the amount, never approves anything and never counts towards a rank.
            'paid_amount'        => ['nullable', 'numeric', 'gt:0', 'max:1000000000000'],
            'paid_currency'      => ['nullable', 'string', 'max:8'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'funding_channel'    => ['nullable', 'in:telegram,app,other'],
        ]);

        if (!empty($validated['paid_currency']) && !app(CurrencyCatalog::class)->isSupported($validated['paid_currency'])) {
            return response()->json(['message' => 'Unsupported currency code.', 'errors' => ['paid_currency' => ['Choose a supported currency code.']]], 422);
        }

        $proofImagePath = $deposit->proof_image;
        if ($request->hasFile('screenshot')) {
            $proofImagePath = \App\Support\ProofStore::save($request->file('screenshot'));
        }

        $deposit->update([
            'payment_method' => $validated['payment_method'],
            'proof_image'    => $proofImagePath,
        ]);

        app(DepositPaymentService::class)->declare($deposit, $validated);

        return response()->json([
            'message' => 'Payment confirmed. Awaiting admin approval.',
            'deposit' => [
                'id'        => $deposit->id,
                'amount'    => (float) $deposit->amount,
                'method'    => $deposit->payment_method,
                'reference' => $deposit->transaction_reference,
                'plan_name' => $deposit->investmentPlan->name ?? null,
                'status'    => $deposit->status,
                'created_at'=> $deposit->created_at->toDateString(),
            ],
        ]);
    }

    // POST /investor-investment/investor/deposits (kept for backward compat)
    public function store(Request $request)
    {
        return $this->initiate($request);
    }

    // GET /investor-investment/investor/deposits/{deposit}
    public function show(Request $request, Deposit $deposit)
    {
        if ($deposit->user_id !== Auth::id()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            abort(403);
        }

        $deposit->load('investmentPlan:id,name');

        $data = [
            'deposit' => [
                'id'           => $deposit->id,
                'amount'       => (float) $deposit->amount,
                'method'       => $deposit->payment_method,
                'reference'    => $deposit->transaction_reference,
                'plan_name'    => $deposit->investmentPlan->name ?? null,
                'status'       => $deposit->status,
                'created_at'   => $deposit->created_at->toDateString(),
                'processed_at' => optional($deposit->processed_at)->toDateString(),
            ],
            'agent' => $this->agentPayload($deposit->user, $deposit->transaction_reference),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.deposits.show', $data);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    protected function investorName($user): string
    {
        return $user->name
            ?? $user->full_name
            ?: (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Investor');
    }

    protected function findByKey(int $userId, string $key): ?Deposit
    {
        return Deposit::where('user_id', $userId)->where('idempotency_key', $key)->first();
    }

    /** The investor's own Telegram contact as the public API may show it: name + link only (never a chat id). */
    protected function agentPayload($user, ?string $reference = null): ?array
    {
        $resolver = app(DepositAgentResolver::class);
        $resolved = $resolver->resolve($user);
        if (!$resolved) return null;
        return $reference
            ? $resolver->publicShape($resolved, $this->investorName($user), $reference)
            : ['name' => $resolved['name'], 'link' => 'https://t.me/' . $resolved['username']];
    }

    /**
     * DM the investor's ASSIGNED agent (only possible when that agent's chat id is stored). Returns true only if Telegram
     * confirmed delivery; a failure is logged and never affects the saved deposit.
     */
    protected function notifyAssignedAgent(Deposit $deposit, $user, string $planName, string $name): bool
    {
        if ($deposit->agent_notified_at) return false; // already notified once: never twice
        try {
            $resolved = app(DepositAgentResolver::class)->resolve($user);
            if (!$resolved || $resolved['source'] !== 'assigned' || empty($resolved['chat_id'])) return false;

            $sent = $this->telegram->notifyAssignedAgentOfDeposit(
                $resolved['chat_id'], $name, $user->account_id, $planName, (float) $deposit->amount, $deposit->transaction_reference
            );
            if ($sent) $deposit->forceFill(['agent_notified_at' => now()])->save();
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('Assigned-agent Telegram notification failed: ' . get_class($e));
            return false;
        }
    }

    /** Stable per-deposit token: calling this twice for one deposit can never create two enquiry messages. */
    protected function enquiryToken(Deposit $deposit): string
    {
        return 'deposit-enquiry-' . $deposit->id;
    }

    protected function openDepositEnquiry(Deposit $deposit, $user, string $planName): void
    {
        try {
            $body = "Deposit request {$deposit->transaction_reference}: $" . number_format((float) $deposit->amount, 2)
                  . " for {$planName}. Please send me the payment instructions for this deposit.";
            app(FinancialConversationService::class)->submit(
                $user, ['body' => $body, 'body_html' => null], 'Deposit enquiry ' . $deposit->transaction_reference,
                Message::TOPIC_DEPOSIT, $deposit, $this->enquiryToken($deposit), true,
                false   // no extra Telegram ping: this flow already sends the staff-chat and assigned-agent deposit alerts
            );
        } catch (\Throwable $e) {
            Log::warning('Deposit enquiry message failed: ' . get_class($e));
        }
    }

    protected function depositResponse(Deposit $deposit, $user, bool $duplicate, bool $notified = false, ?string $planName = null)
    {
        $agent = $this->agentPayload($user, $deposit->transaction_reference);
        return response()->json([
            'deposit_id'     => $deposit->id,
            'reference'      => $deposit->transaction_reference,
            'plan_name'      => $planName ?? $deposit->investmentPlan->name ?? null,
            'amount'         => (float) $deposit->amount,
            'status'         => $deposit->status,
            'agent'          => $agent,                                  // null => frontend shows the "details unavailable" fallback
            'agent_notified' => $duplicate ? (bool) $deposit->agent_notified_at : $notified,   // true only when Telegram confirmed
            'duplicate'      => $duplicate,
            // The in-dashboard conversation about this deposit (the investor continues it on the Messages page).
            'conversation'   => [
                'available'  => Message::where('investor_id', $user->id)->where('client_token', $this->enquiryToken($deposit))->exists(),
                'agent_name' => app(FinancialConversationService::class)->assignedAgent($user)?->name,
            ],
        ], $duplicate ? 200 : 201);
    }
}
