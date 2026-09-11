@extends('layouts.app')

@section('title', 'Task Management - Admin')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Task Management</h1>
        <p class="dashboard-subtitle">Create and manage daily tasks</p>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
        <!-- Create Task Form -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Create New Task</h3>
            
            <form action="{{ route('admin.tasks.create') }}" method="POST">
                @csrf
                
                <div class="form-group">
                    <label class="form-label">Task Title</label>
                    <input type="text" name="title" class="form-input" placeholder="e.g., Review Market Analysis" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Brief description of the task" required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Reward Amount ($)</label>
                    <input type="number" name="reward" step="0.01" class="form-input" placeholder="5.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Active Date</label>
                    <input type="date" name="active_date" class="form-input" value="{{ date('Y-m-d') }}" required>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="is_active" checked>
                        <span>Active immediately</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Create Task
                </button>
            </form>
        </div>

        <!-- Existing Tasks -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Existing Tasks</h3>
            
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Reward</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Completions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tasks ?? [] as $task)
                        <tr>
                            <td>
                                <strong>{{ $task->title }}</strong><br>
                                <span style="font-size: 0.85rem; color: var(--text-secondary);">{{ Str::limit($task->description, 40) }}</span>
                            </td>
                            <td style="font-weight: 700; color: var(--success);">${{ number_format($task->reward, 2) }}</td>
                            <td style="font-size: 0.85rem;">{{ $task->active_date->format('M d, Y') }}</td>
                            <td>
                                <span class="badge badge-{{ $task->is_active ? 'success' : 'danger' }}">
                                    {{ $task->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td style="text-align: center;">{{ $task->completions_count ?? 0 }}</td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button onclick="editTask({{ $task->id }})" class="btn btn-secondary btn-small">Edit</button>
                                    <form action="{{ route('admin.tasks.delete', $task) }}" method="POST" style="display: inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-secondary btn-small" style="background: var(--danger); color: white; border: none;" onclick="return confirm('Delete this task?')">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem;">No tasks created yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function editTask(taskId) {
        alert('Edit task ID: ' + taskId);
        // Implement edit functionality
    }
</script>
@endpush
@endsection