{{-- @extends('layouts.app')

@section('title', 'Transactions - Smart System')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Transaction History</h1>
        <p class="dashboard-subtitle">All your investment activity</p>
    </div>

    <div class="dashboard-card">
        <!-- Filter Options -->
        <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
            <select class="form-input" style="max-width: 200px;">
                <option>All Types</option>
                <option>Deposits</option>
                <option>Withdrawals</option>
                <option>Profits</option>
                <option>Task Rewards</option>
            </select>
            <select class="form-input" style="max-width: 200px;">
                <option>All Status</option>
                <option>Completed</option>
                <option>Pending</option>
                <option>Declined</option>
            </select>
            <input type="date" class="form-input" style="max-width: 200px;">
        </div>

        <!-- Transactions Table -->
        <table>
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Date & Time</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions ?? [] as $tx)
                    <tr>
                        <td style="font-family: monospace; font-size: 0.85rem;">{{ $tx->transaction_id }}</td>
                        <td>{{ $tx->created_at->format('M d, Y H:i') }}</td>
                        <td>
                            <strong>{{ ucfirst($tx->type) }}</strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-secondary);">{{ $tx->method }}</span>
                        </td>
                        <td>{{ $tx->method }}</td>
                        <td style="font-weight: 700; font-size: 1.1rem; color: {{ in_array($tx->type, ['deposit', 'profit', 'task_reward']) ? 'var(--success)' : 'var(--text-primary)' }};">
                            {{ $tx->type === 'withdrawal' ? '-' : '+' }}${{ number_format($tx->amount, 2) }}
                        </td>
                        <td>
                            <span class="badge badge-{{ $tx->status === 'completed' ? 'success' : ($tx->status === 'pending' ? 'warning' : 'danger') }}">
                                {{ $tx->status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                            No transactions found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        <div style="margin-top: 2rem; text-align: center;">
            {{ $transactions->links() ?? '' }}
        </div>
    </div>
</div>
@endsection --}}