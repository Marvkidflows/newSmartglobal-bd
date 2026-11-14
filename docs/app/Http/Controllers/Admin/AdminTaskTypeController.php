<?php
// LOCATION: app/Http/Controllers/Admin/AdminTaskTypeController.php
//
// Admin-configurable task/activity types (Guide §14). Lets an administrator
// add new task types from the Admin Panel with no developer/deploy step.

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaskType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminTaskTypeController extends Controller
{
    // GET /admin/task-types
    public function index(Request $request)
    {
        $types = TaskType::withCount('tasks')->orderBy('label')->get()->map(fn($t) => [
            'id'               => $t->id,
            'key'              => $t->key,
            'label'            => $t->label,
            'icon'             => $t->icon,
            'description'      => $t->description,
            'requires_amount'  => (bool) $t->requires_amount,
            'is_active'        => (bool) $t->is_active,
            'tasks_count'      => $t->tasks_count,
            'created_at'       => $t->created_at->toDateString(),
        ]);

        return response()->json(['task_types' => $types]);
    }

    // POST /admin/task-types
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'            => ['required', 'string', 'max:100'],
            'key'              => ['nullable', 'string', 'max:50', 'alpha_dash', 'unique:task_types,key'],
            'icon'             => ['nullable', 'string', 'max:10'],
            'description'      => ['nullable', 'string', 'max:500'],
            'requires_amount'  => ['nullable', 'boolean'],
        ]);

        $key = $validated['key'] ?? Str::slug($validated['label'], '_');
        // Guarantee uniqueness even if a slugified label collides.
        $baseKey = $key;
        $i = 1;
        while (TaskType::where('key', $key)->exists()) {
            $key = $baseKey . '_' . (++$i);
        }

        $type = TaskType::create([
            'key'             => $key,
            'label'           => $validated['label'],
            'icon'            => $validated['icon'] ?? null,
            'description'     => $validated['description'] ?? null,
            'requires_amount' => $request->boolean('requires_amount', true),
            'is_active'       => true,
            'created_by'      => Auth::id(),
        ]);

        return response()->json([
            'message'   => 'Task type created.',
            'task_type' => $type,
        ], 201);
    }

    // PUT /admin/task-types/{taskType}
    public function update(Request $request, TaskType $taskType)
    {
        $validated = $request->validate([
            'label'            => ['sometimes', 'string', 'max:100'],
            'icon'             => ['nullable', 'string', 'max:10'],
            'description'      => ['nullable', 'string', 'max:500'],
            'requires_amount'  => ['nullable', 'boolean'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        $taskType->update([
            'label'           => $validated['label'] ?? $taskType->label,
            'icon'            => $validated['icon'] ?? $taskType->icon,
            'description'     => $validated['description'] ?? $taskType->description,
            'requires_amount' => $request->has('requires_amount') ? $request->boolean('requires_amount') : $taskType->requires_amount,
            'is_active'       => $request->has('is_active') ? $request->boolean('is_active') : $taskType->is_active,
        ]);

        return response()->json(['message' => 'Task type updated.', 'task_type' => $taskType->fresh()]);
    }

    // DELETE /admin/task-types/{taskType}
    public function destroy(TaskType $taskType)
    {
        if ($taskType->tasks()->exists()) {
            return response()->json([
                'message' => 'This task type is in use by existing tasks and cannot be deleted. Deactivate it instead.',
            ], 422);
        }

        $taskType->delete();
        return response()->json(['message' => 'Task type deleted.']);
    }
}
