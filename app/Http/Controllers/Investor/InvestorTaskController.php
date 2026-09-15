<?php
// LOCATION: app/Http/Controllers/Investor/InvestorTaskController.php
//
// Investor side of the shared-code Task Management System.
//
// SECURITY: a code that exists but that this investor was never assigned
// returns the EXACT SAME generic message as a code that doesn't exist at
// all — an unassigned investor can never learn a code is "real" by testing
// it. Every check here runs server-side; the React countdown is display
// only.
//
// FLOW: an investor can enter their code as soon as they receive it, even
// before the admin's activation window opens. If the task requires an
// amount, the investor types it in (must match the task's required
// amount) AND the system verifies their real account balance actually
// covers it — both checks apply together. If the window hasn't opened yet,
// the code is "confirmed" and the investor sees a countdown to start; the
// assignment then auto-activates the instant the window opens
// (TaskAssignment::syncStart(), checked on every read) — no separate
// action needed from the investor.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Notifications\TaskActivatedNotification;

class InvestorTaskController extends Controller
{
    // GET /investor-investment/tasks
    public function index(Request $request)
    {
        $assignments = TaskAssignment::with('task.taskType')
            ->forUser(Auth::id())
            ->latest()
            ->get();

        $this->syncAll($assignments);

        $balance = (float) (Auth::user()->balance ?? 0);

        $summary = [
            'active'    => $assignments->whereIn('status', ['active', 'in_progress'])->count(),
            'pending'   => $assignments->whereIn('status', ['pending', 'awaiting_activation'])->count(),
            'completed' => $assignments->where('status', 'completed')->count(),
            'expired'   => $assignments->where('status', 'expired')->count(),
        ];

        return response()->json([
            'balance' => $balance,
            'tasks'   => $assignments->map(fn($a) => $this->formatAssignment($a)),
            'summary' => $summary,
        ]);
    }

    // GET /investor-investment/tasks/{taskAssignment}
    public function show(Request $request, TaskAssignment $taskAssignment)
    {
        if ($taskAssignment->user_id !== Auth::id()) {
            abort(404);
        }

        $taskAssignment->load('task.taskType');
        $this->syncAll(collect([$taskAssignment]));

        return response()->json(['task' => $this->formatAssignment($taskAssignment, detailed: true)]);
    }

    // POST /investor-investment/tasks/submit-code
    public function submitCode(Request $request)
    {
        $validated = $request->validate([
            'task_code' => ['required', 'string', 'max:50'],
            'amount'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $task = Task::where('task_code', strtoupper(trim($validated['task_code'])))->first();

        if (!$task) {
            return response()->json(['message' => 'Invalid task code. Please check the code and try again.'], 422);
        }

        $assignment = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', Auth::id())
            ->first();

        // Code exists but this investor was never assigned to it — SAME
        // generic message as a nonexistent code. Recorded for admin
        // visibility only; never surfaced to the requester.
        if (!$assignment) {
            $task->logs()->create([
                'actor_id' => Auth::id(), 'actor_type' => 'investor',
                'action'   => 'code_submission_denied', 'meta' => ['reason' => 'investor_not_assigned'],
            ]);
            return response()->json(['message' => 'Invalid task code. Please check the code and try again.'], 422);
        }

        $task->syncExpiry();
        $assignment->syncExpiryFromTask();
        $assignment->syncStart();
        $assignment->refresh();

        if (in_array($assignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            $message = match ($assignment->status) {
                'expired'   => 'This task code has expired.',
                'completed' => 'This task code has already been used and cannot be reused.',
                'cancelled' => 'This task is no longer available.',
                'failed'    => 'This task is no longer available.',
                default     => 'This task code is no longer valid.',
            };
            return response()->json(['message' => $message], 422);
        }

        if (!in_array($assignment->status, ['pending', 'awaiting_activation'], true)) {
            return response()->json(['message' => 'This task has already been activated.'], 422);
        }

        // Already confirmed and just waiting for the window — idempotent
        // re-submission returns the same countdown info, not an error.
        if ($assignment->code_confirmed_at) {
            return response()->json([
                'message' => 'Code already confirmed. This task will start automatically when the window opens.',
                'task'    => $this->formatAssignment($assignment->load('task.taskType'), detailed: true),
            ]);
        }

        // Amount check — the investor can commit AT LEAST the required
        // amount (their own override if the admin set one, or the task's
        // shared default) — $100, $200, or any amount above it is fine,
        // it doesn't have to match to the penny. Whatever they actually
        // commit must be covered by their real balance, not just the
        // minimum — if they type $700 they need $700 available, not $500.
        $requiredAmount = $assignment->effective_required_amount;
        if ($task->taskType && $task->taskType->requires_amount && $requiredAmount !== null) {
            $submitted = $validated['amount'] ?? null;
            if ($submitted === null) {
                return response()->json(['message' => 'Please enter the required amount for this task.'], 422);
            }
            if (bccomp((string) $submitted, (string) $requiredAmount, 2) === -1) {
                return response()->json([
                    'message' => 'The amount entered is below what this task requires. Minimum: $' . number_format($requiredAmount, 2) . '.',
                ], 422);
            }

            $balance = (float) (Auth::user()->balance ?? 0);
            if ($balance < (float) $submitted) {
                return response()->json([
                    'message' => 'Insufficient balance for this amount. You entered $' . number_format($submitted, 2) . ', your available balance: $' . number_format($balance, 2) . '.',
                ], 422);
            }
        }

        // Deactivation blocks activation even if the window has technically
        // opened — without this check, a fresh code submission arriving
        // while the task sits deactivated would activate anyway, since
        // this calculation only looked at activates_at before.
        $windowOpen = !$task->isDeactivated() && (!$task->activates_at || now()->gte($task->activates_at));

        DB::transaction(function () use ($assignment, $windowOpen, $validated) {
            $from = $assignment->status;
            $assignment->update([
                'code_confirmed_at' => now(),
                'submitted_amount'  => $validated['amount'] ?? $assignment->submitted_amount,
                'status'            => $windowOpen ? 'active' : $assignment->status,
                'activated_at'      => $windowOpen ? now() : null,
            ]);

            $assignment->logs()->create([
                'task_id' => $assignment->task_id,
                'actor_id' => Auth::id(), 'actor_type' => 'investor',
                'action' => $windowOpen ? 'code_submitted' : 'code_confirmed_pending_window',
                'from_status' => $from, 'to_status' => $windowOpen ? 'active' : $from,
            ]);
        });

        $assignment->refresh();

        if ($windowOpen) {
            $assignment->user?->notify(new TaskActivatedNotification($assignment));
            return response()->json([
                'message' => 'TASK ACTIVATED SUCCESSFULLY',
                'task'    => $this->formatAssignment($assignment->load('task.taskType'), detailed: true),
            ]);
        }

        return response()->json([
            'message' => 'Code confirmed! This task will start automatically when the window opens.',
            'task'    => $this->formatAssignment($assignment->load('task.taskType'), detailed: true),
        ]);
    }

    // POST /investor-investment/tasks/{taskAssignment}/submit
    public function submitActivity(Request $request, TaskAssignment $taskAssignment)
    {
        if ($taskAssignment->user_id !== Auth::id()) {
            abort(404);
        }

        $this->syncAll(collect([$taskAssignment]));
        $taskAssignment->refresh();

        if (in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'This task is no longer active and cannot accept a submission.'], 422);
        }
        if (in_array($taskAssignment->status, ['submitted', 'under_review'], true)) {
            return response()->json(['message' => 'This task has already been submitted and is awaiting review.'], 422);
        }
        if (!in_array($taskAssignment->status, ['active', 'in_progress'], true)) {
            return response()->json(['message' => 'This task must be activated before you can submit.'], 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($taskAssignment, $validated) {
            $from = $taskAssignment->status;
            $taskAssignment->update([
                'status'          => 'submitted',
                'submitted_at'    => now(),
                'submitted_notes' => $validated['notes'] ?? null,
            ]);

            $taskAssignment->logs()->create([
                'task_id' => $taskAssignment->task_id,
                'actor_id' => Auth::id(), 'actor_type' => 'investor', 'action' => 'activity_submitted',
                'from_status' => $from, 'to_status' => 'submitted',
            ]);
        });

        return response()->json([
            'message' => 'Submission received. Your task is now under review.',
            'task'    => $this->formatAssignment($taskAssignment->fresh()->load('task.taskType'), detailed: true),
        ]);
    }

    // GET /investor-investment/tasks/{taskAssignment}/logs
    public function logs(TaskAssignment $taskAssignment)
    {
        if ($taskAssignment->user_id !== Auth::id()) {
            abort(404);
        }

        $taskLogs = $taskAssignment->task->logs()->get();
        $assignmentLogs = $taskAssignment->logs()->get();

        $logs = $taskLogs->concat($assignmentLogs)->sortBy('created_at')->values()->map(fn($log) => [
            'action'     => $log->action,
            'status'     => $log->to_status,
            'created_at' => $log->created_at->toDateTimeString(),
        ]);

        return response()->json(['logs' => $logs]);
    }

    /** Runs the full lazy-sync chain (expiry, then auto-start) for a batch
     *  of assignments, syncing each unique parent task's expiry only once. */
    protected function syncAll($assignments): void
    {
        $assignments->pluck('task')->filter()->unique('id')->each(fn(Task $t) => $t->syncExpiry());
        $assignments->each(function (TaskAssignment $a) {
            $a->syncExpiryFromTask();
            $a->syncStart();
        });
    }

    protected function formatAssignment(TaskAssignment $a, bool $detailed = false): array
    {
        $task = $a->task;

        $data = [
            'id'                 => $a->id,
            'task_code'          => $task->task_code,
            'title'              => $task->title,
            'description'        => $task->description,
            'required_amount'    => $a->effective_required_amount,
            'has_sufficient_balance' => $a->effective_required_amount === null || (float) (Auth::user()->balance ?? 0) >= $a->effective_required_amount,
            'status'             => $a->live_status,
            'is_expired'         => $a->live_status === 'expired',
            'is_deactivated'     => $task->isDeactivated(),
            'code_confirmed'     => $a->code_confirmed_at !== null,
            'seconds_remaining'  => $task->seconds_remaining,   // counts down to expiry, once active
            'seconds_until_start'=> $a->seconds_until_start,    // counts down to window opening, pre-activation
            'activates_at'       => optional($task->activates_at)->toISOString(),
            'expires_at'         => optional($task->expires_at)->toISOString(),
            'activated_at'       => optional($a->activated_at)->toISOString(),
            'completed_at'       => optional($a->completed_at)->toISOString(),
            'task_type'          => [
                'key'   => $task->taskType->key ?? null,
                'label' => $task->taskType->label ?? 'N/A',
                'icon'  => $task->taskType->icon ?? null,
            ],
        ];

        if ($detailed) {
            $data += [
                'requirements'     => $task->requirements,
                'submitted_amount' => $a->submitted_amount !== null ? (float) $a->submitted_amount : null,
                'submitted_notes'  => $a->submitted_notes,
                'amount_used'      => in_array($a->status, ['under_review', 'completed'], true) ? (float) ($a->amount_used ?? 0) : null,
                'amount_received'  => in_array($a->status, ['under_review', 'completed'], true) ? (float) ($a->amount_received ?? 0) : null,
                'profit_loss'      => in_array($a->status, ['under_review', 'completed'], true) ? (float) ($a->profit_loss ?? 0) : null,
                'final_result'     => in_array($a->status, ['under_review', 'completed'], true) ? $a->final_result : null,
            ];
        }

        return $data;
    }
}
