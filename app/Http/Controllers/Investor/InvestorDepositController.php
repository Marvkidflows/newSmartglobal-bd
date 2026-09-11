<?php
// LOCATION: app/Http/Controllers/Investor/InvestorDepositController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\InvestmentPlan;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InvestorDepositController extends Controller
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
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
                'admin_notes' => $d->admin_notes,
                'created_at'  => $d->created_at->toDateString(),
            ]);

        if ($request->expectsJson()) {
            return response()->json(['deposits' => $deposits]);
        }
        return view('investor.deposits.index', compact('deposits'));
    }

    // GET /investor-investment/investor/deposits/create
    public function create(Request $request)
    {
        $plans = InvestmentPlan::where('status', 'active')
            ->orderBy('min_amount')
            ->get(['id', 'name', 'min_amount', 'max_amount', 'profit_percentage', 'duration_days']);

        if ($request->expectsJson()) {
            return response()->json(['plans' => $plans, 'agent' => $this->getAgent()]);
        }
        return view('investor.deposits.create', compact('plans'));
    }

    // POST /investor-investment/investor/deposits/initiate
    // Called after Stage 1 — creates deposit, generates reference, fires Telegram to agent immediately
    public function initiate(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'investment_plan_id' => ['required', 'exists:investment_plans,id'],
            'amount'             => ['required', 'numeric', 'min:1'],
        ]);

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

        // Generate reference immediately
        $reference = 'DEP-' . now()->year . '-' . strtoupper(Str::random(8));

        // Create deposit record right away (no payment method yet)
        $deposit = Deposit::create([
            'user_id'               => $user->id,
            'investment_plan_id'    => $plan->id,
            'amount'                => $validated['amount'],
            'payment_method'        => 'pending', // will be updated at confirm step
            'transaction_reference' => $reference,
            'status'                => 'pending',
        ]);

        // Fire Telegram to agent immediately so agent knows to expect this payment
       
// With this:
$name = $user->name
     ?? $user->full_name
     ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
     ?: 'Investor';

$agent = $this->getAgent($name, (float) $deposit->amount, $reference);
$this->telegram->newDeposit($name, (float) $deposit->amount, $reference);
        return response()->json([
            'deposit_id' => $deposit->id,
            'reference'  => $reference,
            'plan_name'  => $plan->name,
            'amount'     => (float) $deposit->amount,
            'agent'      => $agent,
        ], 201);
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
        ]);

        $proofImagePath = $deposit->proof_image;
        if ($request->hasFile('screenshot')) {
            $proofImagePath = $request->file('screenshot')->store('deposit-screenshots', 'public');
        }

        $deposit->update([
            'payment_method' => $validated['payment_method'],
            'proof_image'    => $proofImagePath,
        ]);

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
                'admin_notes'  => $deposit->admin_notes,
                'created_at'   => $deposit->created_at->toDateString(),
                'processed_at' => optional($deposit->processed_at)->toDateString(),
            ],
           'agent' => $this->getAgent(
    $deposit->user->name ?? $deposit->user->full_name ?? 'Investor',
    (float) $deposit->amount,
    $deposit->transaction_reference
),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.deposits.show', $data);
    }

       protected function getAgent(?string $investorName = null, ?float $amount = null, ?string $reference = null): array
    {
        $username = config('services.telegram.agent_username', 'AgentlanFrank');
        $link = 'https://t.me/' . ltrim($username, '@');

        if ($investorName && $amount !== null && $reference) {
            $message = "Hi, I'm {$investorName}. Reference: {$reference}. I want to deposit $" . number_format($amount, 2) . '.';
            $link .= '?text=' . rawurlencode($message);
        }

        return [
            'name'     => config('services.telegram.agent_name', 'AgentlanFrank'),
            'username' => $username,
            'link'     => $link,
        ];
    }
}