@extends('layouts.app')

@section('title', 'Withdrawals - Smart System')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Withdrawal Requests</h1>
        <p class="dashboard-subtitle">Request and track your withdrawals</p>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
        <!-- Withdrawal Form -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">New Withdrawal</h3>
            
            <div style="background: var(--bg-primary); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">Available Balance</p>
                <p style="font-size: 2rem; font-weight: 700; color: var(--primary);">${{ number_format($user->balance, 2) }}</p>
            </div>

            <form action="{{ route('investor.withdrawals.request') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label class="form-label">Withdrawal Amount</label>
                    <input type="number" name="amount" step="0.01" max="{{ $user->balance }}" class="form-input" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="method" class="form-input" required>
                        <option value="">Select method</option>
                        <option>Bitcoin (BTC)</option>
                        <option>Ethereum (ETH)</option>
                        <option>USDT (TRC20)</option>
                        <option>Bank Transfer</option>
                        <option>PayPal</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Details</label>
                    <textarea name="details" class="form-input" rows="3" placeholder="Enter your wallet address or bank account details" required></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Submit Withdrawal Request
                </button>
            </form>
        </div>

        <!-- Withdrawal History -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Withdrawal History</h3>
            
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($withdrawals ?? [] as $withdrawal)
                        <tr>
                            <td>{{ $withdrawal->created_at->format('M d, Y') }}</td>
                            <td style="font-weight: 700;">${{ number_format($withdrawal->amount, 2) }}</td>
                            <td>{{ $withdrawal->method }}</td>
                            <td>
                                <span class="badge badge-{{ $withdrawal->status === 'completed' ? 'success' : ($withdrawal->status === 'pending' ? 'warning' : 'danger') }}">
                                    {{ $withdrawal->status }}
                                </span>
                            </td>
                            <td>
                                @if($withdrawal->status === 'pending')
                                    <button class="btn btn-secondary btn-small">Cancel</button>
                                @else
                                    <span style="font-size: 0.8rem; color: var(--text-secondary);">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                No withdrawal requests yet
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection