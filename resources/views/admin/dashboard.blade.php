@extends('layouts.dashboard')

@section('title', 'Admin Dashboard')

@section('content')
<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Admin Dashboard</h1>
            <p class="dashboard-subtitle">Complete platform control and investor management</p>
        </div>
        <div style="display: flex; gap: 1rem;">
            <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary">
                📢 Send Announcement
            </a>
            <a href="{{ route('admin.investment-plans.create') }}" class="btn btn-success">
                ➕ Create Plan
            </a>
        </div>
    </div>

    <!-- Key Statistics -->
    <div class="stats-grid">
        <div class="stat-card gradient-primary">
            <div class="stat-label">Total Investors</div>
            <div class="stat-value">{{ $totalInvestors ?? 0 }}</div>
            <div class="stat-change positive">{{ $activeInvestors ?? 0 }} Active</div>
        </div>

        <div class="stat-card gradient-success">
            <div class="stat-label">Total Invested</div>
            <div class="stat-value">${{ number_format($totalInvested ?? 0, 0) }}</div>
            <div class="stat-change">Platform Assets</div>
        </div>

        <div class="stat-card gradient-warning">
            <div class="stat-label">Pending Withdrawals</div>
            <div class="stat-value">{{ $pendingWithdrawals ?? 0 }}</div>
            <div class="stat-change">${{ number_format($pendingAmount ?? 0, 0) }}</div>
        </div>

        <div class="stat-card gradient-info">
            <div class="stat-label">New Messages</div>
            <div class="stat-value">{{ $unreadMessages ?? 0 }}</div>
            <div class="stat-change">Requires Response</div>
        </div>
    </div>

    <!-- Main Grid -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">

        <!-- Investment Plans Management -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 Investment Plans</h2>
                <a href="{{ route('admin.investment-plans.index') }}" style="color: var(--primary-light); font-weight: 600; text-decoration: none;">View All →</a>
            </div>
            <div class="card-body">
                @forelse($plans ?? [] as $plan)
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; padding: 1rem; border-bottom: 1px solid var(--gray-200); align-items: center;">
                        <div>
                            <strong>{{ $plan->name }}</strong>
                            <div style="font-size: 0.85rem; color: var(--gray-500);">{{ $plan->duration_months }} months</div>
                        </div>
                        <div style="text-align: center;">
                            <strong>{{ $plan->profit_percentage }}%</strong>
                            <div style="font-size: 0.85rem; color: var(--gray-500);">ROI</div>
                        </div>
                        <div style="text-align: center;">
                            <strong>{{ $plan->getActiveInvestmentsCount() ?? 0 }}</strong>
                            <div style="font-size: 0.85rem; color: var(--gray-500);">Active</div>
                        </div>
                        <a href="{{ route('admin.investment-plans.edit', $plan) }}" class="btn btn-sm btn-secondary">Edit</a>
                    </div>
                @empty
                    <p style="color: var(--gray-500); text-align: center; padding: 2rem;">No plans yet</p>
                @endforelse
            </div>
        </div>

        <!-- Quick Actions Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">⚡ Quick Actions</h3>
                </div>
                <div class="card-body" style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <a href="{{ route('admin.deposits.index') }}" class="btn btn-primary btn-block">
                        💰 Review Deposits
                    </a>
                    <a href="{{ route('admin.withdrawals.index') }}" class="btn btn-warning btn-block">
                        🔄 Process Withdrawals
                    </a>
                    <a href="{{ route('admin.messages.index') }}" class="btn btn-info btn-block">
                        💬 Messages
                    </a>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary btn-block">
                        👥 Manage Investors
                    </a>
                </div>
            </div>

            <!-- System Status -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">✅ System Status</h3>
                </div>
                <div class="card-body" style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Server</span>
                        <span style="color: var(--success); font-weight: 600;">● Online</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Database</span>
                        <span style="color: var(--success); font-weight: 600;">● Connected</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>API</span>
                        <span style="color: var(--success); font-weight: 600;">● Active</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Investments -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">📈 Recent Investments</h2>
            <a href="{{ route('admin.investments.index') }}" style="color: var(--primary-light); font-weight: 600; text-decoration: none;">View All →</a>
        </div>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Investor</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>ROI</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentInvestments ?? [] as $investment)
                        <tr>
                            <td>
                                <strong>{{ $investment->user->full_name ?? $investment->user->name }}</strong>
                                <div style="font-size: 0.85rem; color: var(--gray-500);">{{ $investment->user->email }}</div>
                            </td>
                            <td>{{ $investment->investmentPlan->name ?? 'Plan' }}</td>
                            <td><strong>${{ number_format($investment->amount, 2) }}</strong></td>
                            <td>
                                <span style="color: var(--success); font-weight: 600;">+{{ $investment->investmentPlan->profit_percentage ?? 0 }}%</span>
                            </td>
                            <td>{{ $investment->investmentPlan->duration_months ?? 0 }} months</td>
                            <td>
                                <span class="badge badge-success">Active</span>
                            </td>
                            <td>
                                <a href="{{ route('admin.investments.show', $investment) }}" class="btn btn-sm btn-secondary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--gray-500);">No investments yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Messages Section -->
    <div style="margin-top: 2rem; display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">💬 Recent Messages</h2>
                <a href="{{ route('admin.messages.index') }}" style="color: var(--primary-light); font-weight: 600; text-decoration: none;">View All →</a>
            </div>
            <div class="card-body">
                @forelse($recentMessages ?? [] as $message)
                    <div style="padding: 1rem; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: start;">
                        <div style="flex: 1;">
                            <strong>{{ $message->sender->full_name ?? $message->sender->name }}</strong>
                            <div style="font-size: 0.85rem; color: var(--gray-500);">{{ $message->subject ?? 'No subject' }}</div>
                            <div style="margin-top: 0.5rem; color: var(--gray-700);">{{ Str::limit($message->message, 100) }}</div>
                        </div>
                        <a href="{{ route('admin.messages.show', $message) }}" class="btn btn-sm btn-primary">Reply</a>
                    </div>
                @empty
                    <p style="color: var(--gray-500); text-align: center; padding: 2rem;">No messages yet</p>
                @endforelse
            </div>
        </div>

        <!-- Deposits & Withdrawals Overview -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">💳 Transactions</h3>
            </div>
            <div class="card-body" style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="padding: 1rem; background: var(--gray-50); border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: var(--gray-500); margin-bottom: 0.5rem;">Pending Deposits</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);">{{ $pendingDeposits ?? 0 }}</div>
                </div>
                <div style="padding: 1rem; background: var(--gray-50); border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: var(--gray-500); margin-bottom: 0.5rem;">Pending Withdrawals</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning);">{{ $pendingWithdrawals ?? 0 }}</div>
                </div>
                <div style="padding: 1rem; background: var(--gray-50); border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: var(--gray-500); margin-bottom: 0.5rem;">Total Volume</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);">${{ number_format($totalTransactionVolume ?? 0, 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Investors List -->
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2 class="card-title">👥 Top Investors</h2>
            <a href="{{ route('admin.users.index') }}" style="color: var(--primary-light); font-weight: 600; text-decoration: none;">View All →</a>
        </div>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Investor Name</th>
                        <th>Email</th>
                        <th>Balance</th>
                        <th>Total Invested</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topInvestors ?? [] as $investor)
                        <tr>
                            <td><strong>{{ $investor->full_name ?? $investor->name }}</strong></td>
                            <td>{{ $investor->email }}</td>
                            <td>${{ number_format($investor->balance, 2) }}</td>
                            <td>
                                <strong>${{ number_format($investor->investments_sum_amount ?? 0, 2) }}</strong>
                            </td>
                            <td>
                                <span class="badge {{ $investor->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                                    {{ ucfirst($investor->status) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('admin.users.show', $investor) }}" class="btn btn-sm btn-secondary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--gray-500);">No investors yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
    }

    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    .dashboard-title {
        font-size: 2rem;
        font-weight: 700;
        color: #0F172A;
        font-family: 'Crimson Pro', serif;
    }

    .dashboard-subtitle {
        color: #6B7280;
        font-size: 0.95rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        border: 1px solid #E5E7EB;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        transform: translateY(-4px);
    }

    .stat-card.gradient-primary {
        background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
        border: none;
        color: white;
    }

    .stat-card.gradient-success {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        border: none;
        color: white;
    }

    .stat-card.gradient-warning {
        background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
        border: none;
        color: white;
    }

    .stat-card.gradient-info {
        background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
        border: none;
        color: white;
    }

    .stat-label {
        font-size: 0.85rem;
        font-weight: 500;
        opacity: 0.9;
        margin-bottom: 0.5rem;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        font-family: 'Crimson Pro', serif;
        margin-bottom: 0.5rem;
    }

    .stat-change {
        font-size: 0.85rem;
        opacity: 0.8;
    }

    .stat-change.positive {
        color: #10B981;
    }

    .card {
        background: white;
        border-radius: 12px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem;
        border-bottom: 1px solid #E5E7EB;
    }

    .card-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0F172A;
    }

    .card-body {
        padding: 1.5rem;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
    }

    .table thead {
        background: #F9FAFB;
        border-bottom: 2px solid #E5E7EB;
    }

    .table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.85rem;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table td {
        padding: 1rem;
        border-bottom: 1px solid #E5E7EB;
    }

    .table tbody tr:hover {
        background: #F9FAFB;
    }

    .badge {
        display: inline-block;
        padding: 0.375rem 0.875rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .badge-success {
        background: #D1FAE5;
        color: #065F46;
    }

    .badge-danger {
        background: #FEE2E2;
        color: #991B1B;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
    }

    .btn-primary {
        background: #1E3A8A;
        color: white;
    }

    .btn-primary:hover {
        background: #0F172A;
        box-shadow: 0 8px 16px rgba(30, 58, 138, 0.3);
        transform: translateY(-2px);
    }

    .btn-success {
        background: #10B981;
        color: white;
    }

    .btn-success:hover {
        background: #059669;
    }

    .btn-warning {
        background: #F59E0B;
        color: white;
    }

    .btn-info {
        background: #3B82F6;
        color: white;
    }

    .btn-secondary {
        background: #F3F4F6;
        color: #1E3A8A;
        border: 2px solid #E5E7EB;
    }

    .btn-secondary:hover {
        background: white;
        border-color: #1E3A8A;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }

    .btn-block {
        width: 100%;
        justify-content: center;
    }
</style>
@endsection