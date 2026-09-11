{{-- @extends('layouts.app')

@section('title', 'Daily Tasks - Smart System')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Daily Tasks</h1>
        <p class="dashboard-subtitle">Complete tasks to earn bonus rewards</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
        @forelse($tasks ?? [] as $task)
            <div class="dashboard-card">
                <div style="display: flex; justify-between; align-items: start; margin-bottom: 1rem;">
                    <div class="card-icon" style="width: 50px; height: 50px;">✓</div>
                    <span style="font-size: 1.2rem; font-weight: 700; color: var(--success);">+${{ number_format($task->reward, 2) }}</span>
                </div>
                
                <h3 style="font-weight: 700; font-size: 1.2rem; margin-bottom: 0.5rem; color: var(--primary);">
                    {{ $task->title }}
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem; line-height: 1.6;">
                    {{ $task->description }}
                </p>

                @if($user->hasCompletedTask($task->id))
                    <div class="btn btn-secondary" style="width: 100%; opacity: 0.6; cursor: not-allowed; text-align: center;">
                        ✓ Completed
                    </div>
                @else
                    <form action="{{ route('investor.tasks.complete', $task) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            Complete Task
                        </button>
                    </form>
                @endif
            </div>
        @empty
            <div class="dashboard-card" style="grid-column: 1/-1; text-align: center; padding: 3rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                <h3 style="font-weight: 700; margin-bottom: 0.5rem;">No Tasks Available</h3>
                <p style="color: var(--text-secondary);">Check back tomorrow for new tasks!</p>
            </div>
        @endforelse
    </div>
</div>
@endsection --}}