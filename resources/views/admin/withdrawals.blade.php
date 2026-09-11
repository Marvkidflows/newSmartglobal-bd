@extends('layouts.app')

@section('title', 'Withdrawal Management - Admin')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Withdrawal Management</h1>
        <p class="dashboard-subtitle">Review and process withdrawal requests</p>
    </div>

    <!-- Statistics -->
    <div class="dashboard-grid" style="margin-bottom: 2rem;">
        <div class="dashboard-card">
            <div class="card-label">Pending Requests</div>
            <div class="card-amount" style="color: var(--warning);">{{ $pendingCount ?? 8 }}</div>
        </div>
        <div class="dashboard-card">
            <div class="card-label">Pending Amount</div>
            <div class="card-amount">${{ number_format($pendingAmount ?? 125500, 2) }}</div>
        </div>
        <div class="dashboard-card">
            <div class="card-label">Processed Today</div>
            <div class="card-amount" style="color: var(--success);">{{ $processedToday ?? 15 }}</div>
        </div>
        <div class="dashboard-card">
            <div class="card-label">Total Today</div>
            <div class="card-amount">${{ number_format($totalToday ?? 245000, 2) }}</div>
        </div>
    </div>

    <!-- Withdrawal Requests -->
    <div class="dashboard-card">
        <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Withdrawal Requests</h3>
        
        <!-- Filter -->
        <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
            <select class="form-input" style="max-width: 180px;">
                <option>Pending</option>
                <option>Completed</option>
                <option>Declined</option>
                <option>All</option>
            </select>
            <input type="date" class="form-input" style="max-width: 180px;">
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Details</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($withdrawals ?? [] as $withdrawal)
                    <tr>
                        <td style="font-family: monospace; font-size: 0.85rem;">{{ $withdrawal->transaction_id }}</td>
                        <td>
                            <strong>{{ $withdrawal->user->name }}</strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-secondary);">{{ $withdrawal->user->email }}</span>
                        </td>
                        <td style="font-weight: 700; font-size: 1.1rem; color: var(--primary);">${{ number_format($withdrawal->amount, 2) }}</td>
                        <td>{{ $withdrawal->method }}</td>
                        <td>
                            <button onclick="showDetails('{{ $withdrawal->id }}')" class="btn btn-secondary btn-small">
                                View Details
                            </button>
                        </td>
                        <td style="font-size: 0.85rem;">{{ $withdrawal->created_at->format('M d, Y H:i') }}</td>
                        <td>
                            <span class="badge badge-{{ $withdrawal->status === 'completed' ? 'success' : ($withdrawal->status === 'pending' ? 'warning' : 'danger') }}">
                                {{ $withdrawal->status }}
                            </span>
                        </td>
                        <td>
                            @if($withdrawal->status === 'pending')
                                <div style="display: flex; gap: 0.5rem;">
                                    <form action="{{ route('admin.withdrawals.approve', $withdrawal) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-small" onclick="return confirm('Approve this withdrawal?')">
                                            Approve
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.withdrawals.decline', $withdrawal) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-small" style="background: var(--danger); color: white; border: none;" onclick="return confirm('Decline this withdrawal?')">
                                            Decline
                                        </button>
                                    </form>
                                </div>
                            @else
                                <span style="font-size: 0.8rem; color: var(--text-secondary);">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem;">No withdrawal requests</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
    function showDetails(id) {
        alert('Show withdrawal details for ID: ' + id);
        // Implement details modal
    }
</script>
@endpush
@endsection