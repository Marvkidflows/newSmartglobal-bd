@extends('layouts.dashboard')

@section('title', 'Investor Dashboard')

@section('content')
<div class="dashboard-container">
    <!-- Profile Header -->
    <div style="background: white; border-radius: 12px; padding: 2rem; margin-bottom: 2rem; border: 1px solid #E5E7EB;">
        <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 2rem; align-items: center;">
            <!-- Avatar -->
            <div style="position: relative;">
                @if(Auth::user()->profile_photo)
                    <img src="{{ asset('storage/' . Auth::user()->profile_photo) }}" alt="Profile" 
                         style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #1E3A8A;">
                @else
                    <div style="width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); 
                                color: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700;">
                        {{ strtoupper(substr(Auth::user()->full_name ?? Auth::user()->name, 0, 1)) }}
                    </div>
                @endif
            </div>

            <!-- User Info -->
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 700; color: #0F172A; margin-bottom: 0.5rem; font-family: 'Crimson Pro', serif;">
                    {{ Auth::user()->full_name ?? Auth::user()->name }}
                </h1>
                <p style="color: #6B7280; margin-bottom: 0.5rem;">{{ Auth::user()->email }}</p>
                <p style="color: #9CA3AF; font-size: 0.9rem;">
                    Member since {{ Auth::user()->created_at->format('M d, Y') }}
                </p>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="{{ route('investor-investment.profile.edit') }}" class="btn btn-primary">✏️ Edit Profile</a>
              <a href="{{ route('investor-investment.investments.plans') }}" class="btn btn-success">💰 Invest Now</a>
                <a href="{{ route('investor-investment.messages.create') }}" class="btn btn-info">💬 Contact Admin</a>
            </div>
        </div>
    </div>

    <!-- Key Statistics -->
    <div class="stats-grid">
        <div class="stat-card gradient-primary">
            <div class="stat-label">Total Balance</div>
            <div class="stat-value">${{ number_format(Auth::user()->balance ?? 0, 2) }}</div>
            <div class="stat-change">Available Funds</div>
        </div>

        <div class="stat-card gradient-success">
            <div class="stat-label">Total Invested</div>
            <div class="stat-value">${{ number_format($totalInvested ?? 0, 2) }}</div>
            <div class="stat-change">{{ count($activeInvestments ?? []) }} Active</div>
        </div>

        <div class="stat-card gradient-warning">
            <div class="stat-label">Total Profit Earned</div>
            <div class="stat-value">${{ number_format($totalProfit ?? 0, 2) }}</div>
            <div class="stat-change" style="color: #10B981;">+{{ $profitPercentage ?? 0 }}%</div>
        </div>

        <div class="stat-card gradient-info">
            <div class="stat-label">Active Plans</div>
            <div class="stat-value">{{ count($activeInvestments ?? []) }}</div>
            <div class="stat-change">Currently Running</div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">

        <!-- Portfolio Charts -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 Portfolio Overview</h2>
                <div style="display: flex; gap: 0.5rem;">
                    <button onclick="switchChartType('pie')" style="padding: 0.5rem 1rem; border: 2px solid #1E3A8A; background: white; color: #1E3A8A; border-radius: 6px; font-weight: 600; cursor: pointer;">Pie</button>
                    <button onclick="switchChartType('bar')" style="padding: 0.5rem 1rem; border: 2px solid #E5E7EB; background: white; color: #6B7280; border-radius: 6px; font-weight: 600; cursor: pointer;">Bar</button>
                </div>
            </div>
            <div class="card-body">
                <div style="position: relative; height: 350px;">
                    <canvas id="portfolioChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Portfolio Breakdown -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">💼 Breakdown</h3>
            </div>
            <div class="card-body" style="display: flex; flex-direction: column; gap: 1rem;">
                @php
                    $portfolioItems = [
                        ['name' => 'Real Estate', 'value' => $portfolioData['real_estate'] ?? 0, 'color' => '#1E3A8A'],
                        ['name' => 'Tech Startup', 'value' => $portfolioData['tech_startup'] ?? 0, 'color' => '#10B981'],
                        ['name' => 'Digital Asset', 'value' => $portfolioData['digital_asset'] ?? 0, 'color' => '#F59E0B'],
                        ['name' => 'Cash Reserve', 'value' => $portfolioData['cash_reserve'] ?? 0, 'color' => '#8B5CF6'],
                    ];
                @endphp

                @foreach($portfolioItems as $item)
                    <div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; border-radius: 3px; background: {{ $item['color'] }};"></div>
                                <span style="font-weight: 600;">{{ $item['name'] }}</span>
                            </div>
                            <span style="color: var(--success); font-weight: 600;">${{ number_format($item['value'], 2) }}</span>
                        </div>
                        <div style="width: 100%; height: 6px; background: #F3F4F6; border-radius: 3px; overflow: hidden;">
                            <div style="height: 100%; background: {{ $item['color'] }}; width: {{ $totalInvested > 0 ? (($item['value'] / $totalInvested) * 100) : 0 }}%;"></div>
                        </div>
                    </div>
                @endforeach

                <div style="padding: 1rem; background: #EFF6FF; border-radius: 8px; border-left: 4px solid #1E3A8A; margin-top: 0.5rem;">
                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.25rem;">Total Portfolio</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #1E3A8A;">${{ number_format($totalInvested ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Investments -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h2 class="card-title">⏱️ Active Investments</h2>
            <a href="{{ route('investor-investment.investments.plans') }}" class="btn btn-primary btn-sm">Invest More</a>
        </div>
        <div class="card-body">
            @if(count($activeInvestments ?? []) > 0)
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                    @foreach($activeInvestments ?? [] as $investment)
                        <div style="padding: 1.5rem; border: 2px solid #E5E7EB; border-radius: 10px; background: white;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                                <div>
                                    <h4 style="font-weight: 700; color: #0F172A; margin-bottom: 0.25rem;">
                                        {{ $investment->plan_name }}
                                    </h4>
                                    <span style="display: inline-block; padding: 0.25rem 0.75rem; background: #DBEAFE; color: #1E3A8A; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">
                                        {{ $investment->duration }}
                                    </span>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 1.75rem; font-weight: 700; color: #1E3A8A; font-family: 'Crimson Pro', serif;">
                                        ${{ number_format($investment->amount, 2) }}
                                    </div>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                                <div>
                                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.25rem;">ROI</div>
                                    <div style="font-weight: 700; color: #10B981;">{{ $investment->profit_percentage ?? 0 }}%</div>
                                </div>
                                <div>
                                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.25rem;">Expected Profit</div>
                                    <div style="font-weight: 700; color: #10B981;">${{ number_format($investment->expected_profit ?? 0, 2) }}</div>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div style="margin-bottom: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <span style="font-size: 0.85rem; font-weight: 600; color: #6B7280;">Progress</span>
                                    <span style="font-weight: 700; color: #1E3A8A;">{{ $investment->progress_percentage ?? 0 }}%</span>
                                </div>
                                <div style="width: 100%; height: 8px; background: #F3F4F6; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; background: linear-gradient(90deg, #10B981 0%, #059669 100%); width: {{ $investment->progress_percentage ?? 0 }}%;"></div>
                                </div>
                            </div>

                            <div style="padding: 0.75rem; background: #F9FAFB; border-radius: 8px; text-align: center;">
                                <div style="font-size: 0.85rem; color: #6B7280;">Time Remaining</div>
                                <div style="font-weight: 700; color: #1E3A8A; font-size: 1.25rem;">{{ $investment->days_remaining ?? 0 }} days</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 3rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">🚀</div>
                    <h4 style="color: #0F172A; margin-bottom: 0.5rem;">No Active Investments</h4>
                    <p style="color: #6B7280; margin-bottom: 1.5rem;">Start your investment journey today</p>
                    <a href="{{ route('investor-investment.investments.plans') }}" class="btn btn-primary">Explore Plans</a>
                </div>
            @endif
        </div>
    </div>

    <!-- Transaction History -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">💳 Transaction History</h2>
                <a href="{{ route('investor-investment.deposits.index') }}" style="color: #2563EB; font-weight: 600; text-decoration: none;">View All →</a>
            </div>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions ?? [] as $transaction)
                            <tr>
                                <td>
                                    <strong>{{ $transaction->created_at->format('M d, Y') }}</strong>
                                    <div style="font-size: 0.85rem; color: #6B7280;">{{ $transaction->created_at->format('h:i A') }}</div>
                                </td>
                                <td>
                                    <span style="color: {{ $transaction->type == 'deposit' ? '#10B981' : '#EF4444' }}; font-weight: 600;">
                                        {{ ucfirst($transaction->type) }}
                                    </span>
                                </td>
                                <td>
                                    <strong>${{ number_format($transaction->amount, 2) }}</strong>
                                </td>
                                <td>
                                    <span class="badge badge-{{ $transaction->status == 'completed' ? 'success' : 'warning' }}">
                                        {{ ucfirst($transaction->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: #6B7280;">No transactions yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⚡ Quick Actions</h3>
            </div>
            <div class="card-body" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="{{ route('investor-investment.deposits.create') }}" class="btn btn-success btn-block">
                    💰 Make Deposit
                </a>
                <a href="{{ route('investor-investment.withdrawals.create') }}" class="btn btn-warning btn-block">
                    🔄 Request Withdrawal
                </a>
                <a href="{{ route('investor-investment.messages.create') }}" class="btn btn-info btn-block">
                    💬 Send Message
                </a>
                <a href="{{ route('investor-investment.profile.edit') }}" class="btn btn-secondary btn-block">
                    ⚙️ Profile Settings
                </a>
            </div>
        </div>
    </div>

    <!-- Messages Section -->
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2 class="card-title">💬 Messages with Admin</h2>
            <a href="{{ route('investor-investment.messages.index') }}" style="color: #2563EB; font-weight: 600; text-decoration: none;">View All →</a>
        </div>
        <div class="card-body">
            @forelse($messages ?? [] as $message)
                <div style="padding: 1rem; border-bottom: 1px solid #E5E7EB; display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: start;">
                    <div>
                        <h4 style="font-weight: 700; color: #0F172A; margin-bottom: 0.25rem;">{{ $message->subject ?? 'No subject' }}</h4>
                        <p style="color: #6B7280; margin-bottom: 0.5rem;">{{ Str::limit($message->message, 150) }}</p>
                        <div style="font-size: 0.85rem; color: #9CA3AF;">
                            {{ $message->created_at->diffForHumans() }}
                            @if($message->admin_reply)
                                • <span style="color: #10B981;">✓ Replied</span>
                            @else
                                • <span style="color: #F59E0B;">⏳ Pending</span>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('investor-investment.messages.show', $message) }}" class="btn btn-sm btn-primary">View</a>
                </div>
            @empty
                <div style="text-align: center; padding: 2rem; color: #6B7280;">
                    <p>No messages yet. <a href="{{ route('investor-investment.messages.create') }}" style="color: #1E3A8A; font-weight: 600;">Send one now</a></p>
                </div>
            @endforelse
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
    let chartInstance = null;
    let chartType = 'pie';

    function initChart() {
        const ctx = document.getElementById('portfolioChart');
        if (!ctx) return;

        const data = {
            labels: ['Real Estate', 'Tech Startup', 'Digital Asset', 'Cash Reserve'],
            datasets: [{
                data: [
                    {{ $portfolioData['real_estate'] ?? 0 }},
                    {{ $portfolioData['tech_startup'] ?? 0 }},
                    {{ $portfolioData['digital_asset'] ?? 0 }},
                    {{ $portfolioData['cash_reserve'] ?? 0 }}
                ],
                backgroundColor: ['#1E3A8A', '#10B981', '#F59E0B', '#8B5CF6'],
                borderColor: ['#1E3A8A', '#10B981', '#F59E0B', '#8B5CF6'],
                borderWidth: 2
            }]
        };

        const config = {
            type: chartType,
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 12, weight: '600' },
                            padding: 15
                        }
                    }
                }
            }
        };

        if (chartInstance) {
            chartInstance.destroy();
        }
        chartInstance = new Chart(ctx, config);
    }

    function switchChartType(type) {
        chartType = type;
        initChart();
    }

    document.addEventListener('DOMContentLoaded', initChart);
</script>

<style>
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
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

    .badge-warning {
        background: #FEF3C7;
        color: #92400E;
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

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 1rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        [style*="grid-template-columns: 2fr 1fr"] {
            grid-template-columns: 1fr !important;
        }

        [style*="grid-template-columns: auto 1fr auto"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>
@endsection