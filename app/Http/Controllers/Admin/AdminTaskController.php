<?php
// LOCATION: app/Http/Controllers/Admin/AdminTaskController.php
//
// Re-architected for the shared-code model: ONE Task (with ONE
// admin-provided code) can have MANY TaskAssignment rows (one per selected
// investor — 1, 3, or 100, admin's choice). Route URLs are unchanged from
// the original 1-code-per-investor design so the frontend needed minimal
// changes — {id} in these routes now refers to a TaskAssignment. Actions
// that are inherently shared (window extend/reduce/set, editing the
// definition) resolve to the assignment's parent Task and cascade to every
// investor assigned to it. Actions that are inherently individual
// (activate/deactivate/cancel/verify/complete/etc.) operate on just that
// one assignment.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskActivatedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskWindowUpdatedNotification;

class AdminTaskController extends Controller
{
    // GET /admin/tasks — flat list of assignments (one row per investor,
    // even when several rows share the same task_code).
    public function index(Request $request)
    {
        $query = TaskAssignment::with(['user:id,name,email', 'task.taskType', 'task.creator:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('task_type_id')) {
            $query->whereHas('task', fn($q) => $q->where('task_type_id', $request->task_type_id));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('task', fn($tq) => $tq->where('task_code', 'like', "%{$search}%"))
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $assignments = $query->latest()->get();

        // Server-enforced expiry sweep — sync each unique parent task once,
        // then cascade to its assignments, rather than re-checking the same
        // task's expiry once per investor.
        $assignments->pluck('task')->unique('id')->each(fn(Task $t) => $t->syncExpiry());
        $assignments->each(function (TaskAssignment $a) {
            $a->syncExpiryFromTask();
            $a->syncStart();
        });

        $formatted = $assignments->map(fn($a) => $this->formatAssignment($a));

        $stats = [
            'total'        => $assignments->count(),
            'active'       => $assignments->whereIn('status', ['active', 'in_progress'])->count(),
            'pending'      => $assignments->whereIn('status', ['pending', 'awaiting_activation'])->count(),
            'completed'    => $assignments->where('status', 'completed')->count(),
            'expired'      => $assignments->where('status', 'expired')->count(),
            'under_review' => $assignments->whereIn('status', ['submitted', 'under_review'])->count(),
        ];

        return response()->json(['tasks' => $formatted, 'stats' => $stats]);
    }

    // GET /admin/tasks/{taskAssignment}
    public function show(Request $request, TaskAssignment $taskAssignment)
    {
        $taskAssignment->task->syncExpiry();
        $taskAssignment->syncExpiryFromTask();
        $taskAssignment->syncStart();
        $taskAssignment->load(['user', 'task.taskType', 'task.creator:id,name', 'verifier:id,name']);

        return response()->json(['task' => $this->formatAssignment($taskAssignment, detailed: true)]);
    }

    // POST /admin/tasks
    // Creates ONE Task (with the admin's own code) and ONE TaskAssignment
    // per selected investor — the whole point of the shared-code model.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'task_code'        => [
                'required', 'string', 'max:50', 'alpha_dash',
                'unique:shared_tasks,task_code',
            ],
            'user_ids'         => ['required', 'array', 'min:1'],
            'user_ids.*'       => ['required', 'exists:users,id'],
            'task_type_id'     => ['required', 'exists:task_types,id'],
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'requirements'     => ['nullable', 'string'],
            'required_amount'  => ['nullable', 'numeric', 'min:0'],
            'activates_at'     => ['nullable', 'date'],
            'expires_at'       => ['nullable', 'date', 'after:activates_at'],
        ]);

        // Reject non-investor targets explicitly rather than silently
        // allowing a task to be "assigned" to an admin account.
        $investors = User::whereIn('id', $validated['user_ids'])->where('role', 'investor')->get();
        if ($investors->count() !== count($validated['user_ids'])) {
            return response()->json(['message' => 'One or more selected users are not valid investor accounts.'], 422);
        }

        $activatesAt = $validated['activates_at'] ?? now();
        $task = null;
        $assignments = collect();

        DB::transaction(function () use ($validated, $investors, $activatesAt, &$task, &$assignments) {
            $task = Task::create([
                'task_code'        => strtoupper($validated['task_code']),
                'task_type_id'     => $validated['task_type_id'],
                'title'            => $validated['title'],
                'description'      => $validated['description'] ?? null,
                'requirements'     => $validated['requirements'] ?? null,
                'required_amount'  => $validated['required_amount'] ?? null,
                'status'           => 'active',
                'activates_at'     => $activatesAt,
                'expires_at'       => $validated['expires_at'] ?? null,
                'created_by'       => Auth::id(),
            ]);

            $task->logs()->create([
                'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'created',
                'to_status' => 'active', 'meta' => ['assigned_investor_ids' => $investors->pluck('id')],
            ]);

            foreach ($investors as $investor) {
                $assignment = TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $investor->id,
                    'status'  => 'awaiting_activation',
                ]);
                $assignments->push($assignment);
                $investor->notify(new TaskAssignedNotification($assignment));
            }
        });

        return response()->json([
            'message' => "Task created — code {$task->task_code} assigned to " . $assignments->count() . ' investor(s).',
            'task'    => $this->formatAssignment($assignments->first()->fresh()->load(['user', 'task.taskType'])),
            'tasks'   => $assignments->map(fn($a) => $this->formatAssignment($a->fresh()->load(['user', 'task.taskType']))),
        ], 201);
    }

    // PUT /admin/tasks/{taskAssignment}
    // Edits the SHARED task definition (cascades to every assignee) —
    // blocked once any investor has moved past pre-activation, to avoid
    // silently rewriting a task people may already be acting on.
    public function update(Request $request, TaskAssignment $taskAssignment)
    {
        $task = $taskAssignment->task;
        $task->syncExpiry();

        $anyProgressed = $task->assignments()->whereNotIn('status', ['pending', 'awaiting_activation'])->exists();
        if ($anyProgressed) {
            return response()->json([
                'message' => 'This task can no longer be edited because at least one investor has already activated or progressed on it.',
            ], 422);
        }

        $validated = $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'requirements'     => ['nullable', 'string'],
            'required_amount'  => ['nullable', 'numeric', 'min:0'],
            'task_type_id'     => ['sometimes', 'exists:task_types,id'],
        ]);

        $task->update($validated);

        $task->logs()->create([
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'updated',
            'meta' => ['fields' => array_keys($validated)],
        ]);

        return response()->json(['message' => 'Task updated.', 'task' => $this->formatAssignment($taskAssignment->fresh()->load(['user', 'task.taskType']))]);
    }

    // POST /admin/tasks/{taskAssignment}/amount
    // Sets (or clears) a required-amount override for just THIS investor,
    // independent of everyone else sharing the code — this is what makes
    // "the required amount varies per investor" possible. Allowed any time
    // before this specific assignment has activated; unlike update() this
    // is NOT blocked by other investors having progressed, since it only
    // touches one investor's own record.
    public function setAmount(Request $request, TaskAssignment $taskAssignment)
    {
        if (!in_array($taskAssignment->status, ['pending', 'awaiting_activation'], true)) {
            return response()->json([
                'message' => 'This investor has already activated — their amount can no longer be changed.',
            ], 422);
        }

        $validated = $request->validate([
            'required_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $taskAssignment->update(['required_amount' => $validated['required_amount'] ?? null]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'amount_override_set',
            'meta' => ['required_amount' => $validated['required_amount'] ?? null],
        ]);

        return response()->json([
            'message' => $validated['required_amount'] !== null
                ? 'Custom amount set for this investor: $' . number_format($validated['required_amount'], 2) . '.'
                : 'Reverted to the default task amount for this investor.',
            'task' => $this->formatAssignment($taskAssignment->fresh()->load(['user', 'task.taskType'])),
        ]);
    }

    // POST /admin/tasks/{taskAssignment}/activate — manual admin override
    // for THIS investor's own assignment.
    public function activate(Request $request, TaskAssignment $taskAssignment)
    {
        $taskAssignment->task->syncExpiry();
        $taskAssignment->syncExpiryFromTask();

        if (in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'This investor\'s task has already reached a final state and cannot be activated.'], 422);
        }

        $from = $taskAssignment->status;
        $taskAssignment->update(['status' => 'active', 'activated_at' => now()]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'activated',
            'from_status' => $from, 'to_status' => 'active',
        ]);

        $taskAssignment->user?->notify(new TaskActivatedNotification($taskAssignment));

        return response()->json(['message' => 'Task activated for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // POST /admin/tasks/{taskAssignment}/deactivate
    public function deactivate(Request $request, TaskAssignment $taskAssignment)
    {
        if (in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'This investor\'s task has already reached a final state.'], 422);
        }

        $from = $taskAssignment->status;
        $taskAssignment->update(['status' => 'awaiting_activation', 'activated_at' => null]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'deactivated',
            'from_status' => $from, 'to_status' => 'awaiting_activation',
        ]);

        return response()->json(['message' => 'Deactivated for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // POST /admin/tasks/{taskAssignment}/cancel — cancels THIS investor's
    // participation only. Other investors sharing the same code are
    // unaffected. Use the task-level closeTask() to shut it down for
    // everyone at once.
    public function cancel(Request $request, TaskAssignment $taskAssignment)
    {
        if (in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'This investor\'s task has already reached a final state.'], 422);
        }

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $from = $taskAssignment->status;
        $taskAssignment->update(['status' => 'cancelled', 'closed_at' => now()]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'cancelled',
            'from_status' => $from, 'to_status' => 'cancelled', 'meta' => ['reason' => $validated['reason'] ?? null],
        ]);

        return response()->json(['message' => 'Cancelled for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // POST /admin/tasks/{taskAssignment}/mark-failed
    public function markFailed(Request $request, TaskAssignment $taskAssignment)
    {
        if (in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'This investor\'s task has already reached a final state.'], 422);
        }

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $from = $taskAssignment->status;
        $taskAssignment->update([
            'status' => 'failed', 'final_result' => 'failed',
            'result_notes' => $validated['reason'] ?? $taskAssignment->result_notes, 'closed_at' => now(),
        ]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'marked_failed',
            'from_status' => $from, 'to_status' => 'failed', 'meta' => ['reason' => $validated['reason'] ?? null],
        ]);

        return response()->json(['message' => 'Marked as failed for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // ── WINDOW MANAGEMENT — shared: cascades to every assignee ──────────────

    public function extendWindow(Request $request, TaskAssignment $taskAssignment)
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'reason'  => ['nullable', 'string', 'max:255'],
        ]);

        return $this->applyWindowChange($taskAssignment->task, function ($task) use ($validated) {
            $base = $task->expires_at ?? now();
            return Carbon::parse($base)->addMinutes($validated['minutes']);
        }, 'extend', $validated['reason'] ?? null, "extended by {$validated['minutes']} minute(s)", $taskAssignment);
    }

    public function reduceWindow(Request $request, TaskAssignment $taskAssignment)
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'reason'  => ['nullable', 'string', 'max:255'],
        ]);

        $task = $taskAssignment->task;
        if (!$task->expires_at) {
            return response()->json(['message' => 'This task has no expiration window set yet.'], 422);
        }
        $newDate = Carbon::parse($task->expires_at)->subMinutes($validated['minutes']);
        if ($newDate->lte(now())) {
            return response()->json(['message' => 'Cannot reduce the window to a time in the past. Cancel individual assignments instead.'], 422);
        }

        return $this->applyWindowChange($task, fn() => $newDate, 'reduce', $validated['reason'] ?? null, "reduced by {$validated['minutes']} minute(s)", $taskAssignment);
    }

    public function setWindow(Request $request, TaskAssignment $taskAssignment)
    {
        $validated = $request->validate([
            'activates_at' => ['nullable', 'date'],
            'expires_at'   => ['required', 'date', 'after:now'],
            'reason'       => ['nullable', 'string', 'max:255'],
        ]);

        $task = $taskAssignment->task;
        $task->syncExpiry();
        if (in_array($task->status, ['cancelled', 'closed'], true)) {
            return response()->json(['message' => 'This task has already been closed or cancelled.'], 422);
        }

        $previous = $task->expires_at;
        $task->expires_at = Carbon::parse($validated['expires_at']);
        if (!empty($validated['activates_at'])) {
            $task->activates_at = Carbon::parse($validated['activates_at']);
        }
        $task->last_window_update = now();
        $task->window_modified_by = Auth::id();
        $task->window_modified_reason = $validated['reason'] ?? null;
        if ($task->status === 'expired') {
            $task->status = 'active';
        }
        $task->save();

        $task->logs()->create([
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'window_set',
            'meta' => ['previous_expires_at' => optional($previous)->toDateTimeString(), 'new_expires_at' => $task->expires_at->toDateTimeString(), 'reason' => $validated['reason'] ?? null],
        ]);

        $this->notifyAllAssignees($task, "Your task \"{$task->title}\" window has been updated. New expiry: {$task->expires_at->format('M j, Y g:i A')}.");

        return response()->json(['message' => 'Task window updated for all assigned investors.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    protected function applyWindowChange(Task $task, callable $newExpiry, string $action, ?string $reason, string $humanSummary, TaskAssignment $contextAssignment)
    {
        $task->syncExpiry();
        if (in_array($task->status, ['cancelled', 'closed'], true)) {
            return response()->json(['message' => 'This task has already been closed or cancelled.'], 422);
        }

        $previous = $task->expires_at;
        $task->expires_at = $newExpiry($task);
        $task->last_window_update = now();
        $task->window_modified_by = Auth::id();
        $task->window_modified_reason = $reason;
        if ($task->status === 'expired') {
            $task->status = 'active';
        }
        $task->save();

        $task->logs()->create([
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => $action,
            'meta' => ['previous_expires_at' => optional($previous)->toDateTimeString(), 'new_expires_at' => $task->expires_at->toDateTimeString(), 'reason' => $reason],
        ]);

        $this->notifyAllAssignees($task, "Your task \"{$task->title}\" window has been {$humanSummary}. New expiry: {$task->expires_at->format('M j, Y g:i A')}.");

        return response()->json(['message' => "Task window {$humanSummary} for all assigned investors.", 'task' => $this->formatAssignment($contextAssignment->fresh())]);
    }

    protected function notifyAllAssignees(Task $task, string $message): void
    {
        $task->assignments()->with('user')->get()->each(function (TaskAssignment $a) use ($message) {
            $a->user?->notify(new TaskWindowUpdatedNotification($message, $a->task->task_code, $a->id));
        });
    }

    // POST /admin/tasks/{taskAssignment}/close-task — shuts the shared code
    // down for EVERY investor at once (task-level, not just this row).
    public function closeTask(Request $request, TaskAssignment $taskAssignment)
    {
        $task = $taskAssignment->task;
        $task->update(['status' => 'closed', 'closed_at' => now()]);

        $task->logs()->create([
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'task_closed',
        ]);

        // Any investor still mid-flight gets cancelled; anyone already
        // terminal (completed/failed/etc.) keeps their own outcome.
        $task->assignments()->whereNotIn('status', TaskAssignment::TERMINAL_STATUSES)->get()->each(function (TaskAssignment $a) {
            $a->update(['status' => 'cancelled', 'closed_at' => now()]);
            $a->logs()->create([
                'task_id' => $a->task_id, 'actor_id' => Auth::id(), 'actor_type' => 'admin',
                'action' => 'cancelled', 'to_status' => 'cancelled', 'meta' => ['reason' => 'task closed for all investors'],
            ]);
        });

        return response()->json(['message' => 'Task closed for all assigned investors.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // GET /admin/tasks/{taskAssignment}/logs — merged task-level +
    // assignment-level timeline.
    public function logs(TaskAssignment $taskAssignment)
    {
        $taskLogs = $taskAssignment->task->logs()->with('actor:id,name')->get();
        $assignmentLogs = $taskAssignment->logs()->with('actor:id,name')->get();

        $logs = $taskLogs->concat($assignmentLogs)->sortByDesc('created_at')->values()->map(fn($log) => [
            'id'          => $log->id,
            'actor'       => $log->actor->name ?? ucfirst($log->actor_type),
            'actor_type'  => $log->actor_type,
            'action'      => $log->action,
            'from_status' => $log->from_status,
            'to_status'   => $log->to_status,
            'meta'        => $log->meta,
            'created_at'  => $log->created_at->toDateTimeString(),
        ]);

        return response()->json(['logs' => $logs]);
    }

    // ── REVIEW / RESULT / COMPLETION — per investor ─────────────────────────

    public function review(Request $request, TaskAssignment $taskAssignment)
    {
        if ($taskAssignment->status !== 'submitted') {
            return response()->json(['message' => 'Only a submitted assignment can be moved to review.'], 422);
        }

        $taskAssignment->update(['status' => 'under_review']);
        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'review_started',
            'from_status' => 'submitted', 'to_status' => 'under_review',
        ]);

        return response()->json(['message' => 'Moved to review.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    public function verify(Request $request, TaskAssignment $taskAssignment)
    {
        if (!in_array($taskAssignment->status, ['submitted', 'under_review'], true)) {
            return response()->json(['message' => 'Only a submitted or under-review assignment can be verified.'], 422);
        }

        $validated = $request->validate([
            'amount_used'     => ['nullable', 'numeric'],
            'amount_received' => ['nullable', 'numeric'],
            'profit_loss'     => ['nullable', 'numeric'],
            'final_result'    => ['nullable', 'string', 'max:100'],
            'result_notes'    => ['nullable', 'string'],
        ]);

        $taskAssignment->update([
            'amount_used'     => $validated['amount_used']     ?? $taskAssignment->amount_used,
            'amount_received' => $validated['amount_received'] ?? $taskAssignment->amount_received,
            'profit_loss'     => $validated['profit_loss']     ?? $taskAssignment->profit_loss,
            'final_result'    => $validated['final_result']    ?? $taskAssignment->final_result,
            'result_notes'    => $validated['result_notes']    ?? $taskAssignment->result_notes,
            'status'          => 'under_review',
            'verified_by'     => Auth::id(),
            'verified_at'     => now(),
        ]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'verified',
            'to_status' => 'under_review', 'meta' => $validated,
        ]);

        return response()->json(['message' => 'Result recorded and verified.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    public function complete(Request $request, TaskAssignment $taskAssignment)
    {
        if (!in_array($taskAssignment->status, ['submitted', 'under_review'], true)) {
            return response()->json(['message' => 'Only a submitted or under-review assignment can be completed.'], 422);
        }

        $from = $taskAssignment->status;
        $taskAssignment->update(['status' => 'completed', 'completed_at' => now()]);

        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'completed',
            'from_status' => $from, 'to_status' => 'completed',
        ]);

        $taskAssignment->user?->notify(new TaskCompletedNotification($taskAssignment));

        return response()->json(['message' => 'Marked as completed for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // POST /admin/tasks/{taskAssignment}/close — finalizes just THIS
    // investor's already-terminal record.
    public function close(Request $request, TaskAssignment $taskAssignment)
    {
        if (!in_array($taskAssignment->status, TaskAssignment::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'Only an assignment in a final state (completed, expired, cancelled, or failed) can be closed.'], 422);
        }

        $taskAssignment->update(['closed_at' => now()]);
        $taskAssignment->logs()->create([
            'task_id' => $taskAssignment->task_id,
            'actor_id' => Auth::id(), 'actor_type' => 'admin', 'action' => 'closed',
        ]);

        return response()->json(['message' => 'Closed for this investor.', 'task' => $this->formatAssignment($taskAssignment->fresh())]);
    }

    // ── SHARED FORMATTER ──────────────────────────────────────────────────

    protected function formatAssignment(TaskAssignment $a, bool $detailed = false): array
    {
        $task = $a->task;

        $data = [
            'id'                => $a->id, // assignment id — used throughout the URL for actions
            'task_id'           => $task->id,
            'task_code'         => $task->task_code,
            'title'             => $task->title,
            'description'       => $task->description,
            'requirements'      => $task->requirements,
            'required_amount'   => $a->effective_required_amount,
            'default_required_amount' => $task->required_amount !== null ? (float) $task->required_amount : null,
            'has_amount_override' => $a->required_amount !== null,
            'status'            => $a->live_status,
            'is_expired'        => $a->live_status === 'expired',
            'seconds_remaining' => $task->seconds_remaining,
            'code_confirmed'    => $a->code_confirmed_at !== null,
            'seconds_until_start' => $a->seconds_until_start,
            'activates_at'      => optional($task->activates_at)->toISOString(),
            'expires_at'        => optional($task->expires_at)->toISOString(),
            'activated_at'      => optional($a->activated_at)->toISOString(),
            'submitted_at'      => optional($a->submitted_at)->toISOString(),
            'completed_at'      => optional($a->completed_at)->toISOString(),
            'closed_at'         => optional($a->closed_at)->toISOString(),
            'created_at'        => $a->created_at->toISOString(),
            'task_type'         => [
                'id'    => $task->taskType->id ?? null,
                'key'   => $task->taskType->key ?? null,
                'label' => $task->taskType->label ?? 'N/A',
                'icon'  => $task->taskType->icon ?? null,
            ],
            'investor' => [
                'id'    => $a->user->id ?? null,
                'name'  => $a->user->name ?? 'Unknown',
                'email' => $a->user->email ?? '',
            ],
            'shared_with_count' => $task->assignments()->count(),
        ];

        if ($detailed) {
            $data += [
                'submitted_amount' => $a->submitted_amount !== null ? (float) $a->submitted_amount : null,
                'submitted_notes'  => $a->submitted_notes,
                'amount_used'      => $a->amount_used !== null ? (float) $a->amount_used : null,
                'amount_received'  => $a->amount_received !== null ? (float) $a->amount_received : null,
                'profit_loss'      => $a->profit_loss !== null ? (float) $a->profit_loss : null,
                'final_result'     => $a->final_result,
                'result_notes'     => $a->result_notes,
                'created_by'       => $task->creator->name ?? null,
                'verified_by'      => $a->verifier->name ?? null,
                'verified_at'      => optional($a->verified_at)->toISOString(),
                'logs'             => [],
            ];
        }

        return $data;
    }
}
