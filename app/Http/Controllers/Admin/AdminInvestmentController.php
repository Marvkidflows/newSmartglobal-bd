<?php
// LOCATION: app/Http/Controllers/Admin/AdminInvestmentController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvestmentAccount;
use Illuminate\Http\Request;
use App\Models\InvestmentCountdownLog;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AdminInvestmentController extends Controller
{
    // GET /admin/investments
    public function index(Request $request)
    {
        $query = InvestmentAccount::with(['user:id,name,email', 'investmentPlan'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $investments = $query->get()->map(fn($i) => $this->formatInvestment($i));

        if ($request->expectsJson()) {
            return response()->json(['investments' => $investments]);
        }
        return view('admin.investments.index', compact('investments'));
    }

    // GET /admin/investments/{investment}
    public function show(Request $request, InvestmentAccount $investment)
    {
        $investment->load(['user', 'investmentPlan']);
        $data = ['investment' => $this->formatInvestment($investment)];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('admin.investments.show', compact('investment'));
    }

    // POST /admin/investments/{investment}/complete
    public function complete(Request $request, InvestmentAccount $investment)
    {
        $investment->update([
            'status' => 'completed',
        ]);

        // Credit profit to user's balance
        $user = $investment->user;
        if ($user) {
            $user->increment('balance', $investment->expected_profit ?? 0);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Investment marked as completed. Profit credited.']);
        }
        return back()->with('success', 'Investment completed.');
    }

    // POST /admin/investments/{investment}/countdown/extend
    public function extendCountdown(Request $request, InvestmentAccount $investment)
    {
        $validated = $request->validate([
            'days'   => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $previous = $investment->end_date;
        $investment->end_date = Carbon::parse($investment->end_date)->addDays($validated['days']);
        $investment->last_countdown_update = now();
        $investment->countdown_modified_by = Auth::id();
        $investment->countdown_modified_reason = $validated['reason'] ?? null;
        $investment->syncRemainingDays();

        InvestmentCountdownLog::create([
            'investment_account_id' => $investment->id,
            'admin_id'              => Auth::id(),
            'previous_end_date'     => $previous,
            'new_end_date'          => $investment->end_date,
            'action'                => 'extend',
            'days_changed'          => $validated['days'],
            'reason'                => $validated['reason'] ?? null,
        ]);

        $this->notifyCountdownChange($investment, "Your investment countdown has been extended by {$validated['days']} days. New end date: {$investment->end_date->format('M j, Y')}.");

        return response()->json([
            'message'    => "Countdown extended by {$validated['days']} days.",
            'investment' => $this->formatInvestment($investment->refresh()),
        ]);
    }

    // POST /admin/investments/{investment}/countdown/reduce
    public function reduceCountdown(Request $request, InvestmentAccount $investment)
    {
        $validated = $request->validate([
            'days'   => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $previous = $investment->end_date;
        $newDate = Carbon::parse($investment->end_date)->subDays($validated['days']);

        if ($newDate->lt(Carbon::parse($investment->start_date))) {
            return response()->json(['message' => 'Cannot reduce countdown below the investment start date.'], 422);
        }

        $investment->end_date = $newDate;
        $investment->last_countdown_update = now();
        $investment->countdown_modified_by = Auth::id();
        $investment->countdown_modified_reason = $validated['reason'] ?? null;
        $investment->syncRemainingDays();

        InvestmentCountdownLog::create([
            'investment_account_id' => $investment->id,
            'admin_id'              => Auth::id(),
            'previous_end_date'     => $previous,
            'new_end_date'          => $investment->end_date,
            'action'                => 'reduce',
            'days_changed'          => -$validated['days'],
            'reason'                => $validated['reason'] ?? null,
        ]);

        $this->notifyCountdownChange($investment, "Your investment countdown has been reduced by {$validated['days']} days. New end date: {$investment->end_date->format('M j, Y')}.");

        return response()->json([
            'message'    => "Countdown reduced by {$validated['days']} days.",
            'investment' => $this->formatInvestment($investment->refresh()),
        ]);
    }

    // POST /admin/investments/{investment}/countdown/set-date
    public function setCountdownDate(Request $request, InvestmentAccount $investment)
    {
        $validated = $request->validate([
            'end_date' => ['required', 'date', 'after:today'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        $previous = $investment->end_date;
        $investment->end_date = Carbon::parse($validated['end_date']);
        $investment->last_countdown_update = now();
        $investment->countdown_modified_by = Auth::id();
        $investment->countdown_modified_reason = $validated['reason'] ?? null;
        $investment->syncRemainingDays();

        InvestmentCountdownLog::create([
            'investment_account_id' => $investment->id,
            'admin_id'              => Auth::id(),
            'previous_end_date'     => $previous,
            'new_end_date'          => $investment->end_date,
            'action'                => 'set_date',
            'reason'                => $validated['reason'] ?? null,
        ]);

        $this->notifyCountdownChange($investment, "Your investment end date has been updated to {$investment->end_date->format('M j, Y')}.");

        return response()->json([
            'message'    => 'End date updated.',
            'investment' => $this->formatInvestment($investment->refresh()),
        ]);
    }

    // POST /admin/investments/{investment}/countdown/override
    public function overrideCountdown(Request $request, InvestmentAccount $investment)
    {
        $validated = $request->validate([
            'remaining_days' => ['required', 'integer', 'min:0', 'max:1000'],
            'reason'         => ['nullable', 'string', 'max:255'],
        ]);

        $previous = $investment->end_date;
        $investment->end_date = Carbon::today()->addDays($validated['remaining_days']);
        $investment->last_countdown_update = now();
        $investment->countdown_modified_by = Auth::id();
        $investment->countdown_modified_reason = $validated['reason'] ?? null;
        $investment->syncRemainingDays();

        InvestmentCountdownLog::create([
            'investment_account_id' => $investment->id,
            'admin_id'              => Auth::id(),
            'previous_end_date'     => $previous,
            'new_end_date'          => $investment->end_date,
            'action'                => 'override_days',
            'days_changed'          => $validated['remaining_days'],
            'reason'                => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'message'    => 'Countdown manually set.',
            'investment' => $this->formatInvestment($investment->refresh()),
        ]);
    }

    // GET /admin/investments/{investment}/countdown/logs
    public function countdownLogs(InvestmentAccount $investment)
    {
        $logs = $investment->countdownLogs()
            ->with('admin:id,name')
            ->latest()
            ->get()
            ->map(fn($log) => [
                'id'                => $log->id,
                'action'            => $log->action,
                'previous_end_date' => $log->previous_end_date->toDateString(),
                'new_end_date'      => $log->new_end_date->toDateString(),
                'days_changed'      => $log->days_changed,
                'reason'            => $log->reason,
                'modified_by'       => $log->admin->name ?? 'Admin',
                'created_at'        => $log->created_at->toDateTimeString(),
            ]);

        return response()->json(['logs' => $logs]);
    }

    protected function notifyCountdownChange(InvestmentAccount $investment, string $message): void
    {
        $user = $investment->user;
        if ($user) {
            $user->notify(new \App\Notifications\CountdownUpdatedNotification($message));
        }
    }

    /**
     * Shared payload builder — live countdown calculated from end_date,
     * consistent with the investor-side controllers (dashboard + investments page).
     */
    protected function formatInvestment(InvestmentAccount $i): array
    {
        $plan      = $i->investmentPlan;
        $totalDays = $plan?->duration_days ?? (($plan?->duration_months ?? 1) * 30);

        $liveRemaining = $i->end_date
            ? max(0, (int) ceil(Carbon::today()->diffInDays(Carbon::parse($i->end_date), false)))
            : ($i->remaining_days ?? $totalDays);

        $elapsed  = max(0, $totalDays - $liveRemaining);
        $progress = $totalDays > 0 ? round(($elapsed / $totalDays) * 100) : 0;

        $isPaid = (bool) ($i->is_paid ?? false);
        $countdownStatus = $isPaid
            ? 'paid'
            : ($liveRemaining <= 0
                ? 'matured'
                : ($liveRemaining <= 7 ? 'maturing_soon' : 'active'));

        return [
            'id'               => $i->id,
            'amount'           => (float) $i->amount,
            'profit_percent'   => $plan->profit_percentage ?? 0,
            'expected_profit'  => (float) ($i->expected_profit ?? 0),
            'total_return'     => (float) ($i->total_return ?? 0),
            'status'           => $i->status,
            'start_date'       => optional($i->start_date)->toDateString(),
            'end_date'         => optional($i->end_date)->toDateString(),
            'days_remaining'   => $liveRemaining,
            'total_days'       => $totalDays,
            'progress'         => min(100, max(0, $progress)),
            'countdown_status' => $countdownStatus,
            'is_paid'          => $isPaid,
            'created_at'       => $i->created_at->toDateString(),
            'plan' => [
                'id'   => $plan->id ?? null,
                'name' => $plan->name ?? 'N/A',
            ],
            'user' => [
                'id'    => $i->user->id ?? null,
                'name'  => $i->user->name ?? 'Unknown',
                'email' => $i->user->email ?? '',
            ],
        ];
    }
}