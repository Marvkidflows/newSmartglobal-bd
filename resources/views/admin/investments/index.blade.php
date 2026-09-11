@extends('layouts.dashboard')

@section('title', 'All Investments')

@section('content')

<div style="background-color: #F9FAFB; min-height: 100vh; padding: 2rem;">
    
    <!-- Header -->
    <div style="margin-bottom: 2rem;">
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin: 0 0 0.5rem 0; font-family: 'Crimson Pro', serif;">
            All Investments
        </h1>
        <p style="color: #6B7280; margin-top: 0.5rem;">Monitor all investor investments and performance</p>
    </div>

    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Total Investments -->
        <div style="background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; border-top: 4px solid #2563EB;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Total Investments</p>
                    <p style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin: 0; font-family: 'Crimson Pro', serif;">
                        {{ $stats['total'] }}
                    </p>
                </div>
                <div style="font-size: 2rem;">📊</div>
            </div>
        </div>

        <!-- Active Investments -->
        <div style="background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; border-top: 4px solid #10B981;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Active Investments</p>
                    <p style="font-size: 2.5rem; font-weight: 700; color: #10B981; margin: 0; font-family: 'Crimson Pro', serif;">
                        {{ $stats['active'] }}
                    </p>
                </div>
                <div style="font-size: 2rem;">✅</div>
            </div>
        </div>

        <!-- Total Invested -->
        <div style="background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; border-top: 4px solid #8B5CF6;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Total Invested</p>
                    <p style="font-size: 2.5rem; font-weight: 700; color: #8B5CF6; margin: 0; font-family: 'Crimson Pro', serif;">
                        ${{ number_format($stats['totalAmount'] ?? 0, 0) }}
                    </p>
                </div>
                <div style="font-size: 2rem;">💰</div>
            </div>
        </div>

        <!-- Total Profit -->
        <div style="background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; border-top: 4px solid #F59E0B;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Total Profit</p>
                    <p style="font-size: 2.5rem; font-weight: 700; color: #F59E0B; margin: 0; font-family: 'Crimson Pro', serif;">
                        ${{ number_format($stats['totalProfit'] ?? 0, 0) }}
                    </p>
                </div>
                <div style="font-size: 2rem;">📈</div>
            </div>
        </div>
    </div>

    <!-- Investments Table -->
    <div style="background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; overflow: hidden;">
        
        <!-- Table Header -->
        <div style="padding: 1.5rem; background: linear-gradient(135deg, #F9FAFB 0%, #F3F4F6 100%); border-bottom: 2px solid #E5E7EB;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #1F2937;">Investment Records</h3>
        </div>

        <!-- Table -->
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: #F9FAFB; border-bottom: 2px solid #E5E7EB;">
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">User</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Plan</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Amount</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Expected Profit</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Status</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Days Left</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #6B7280; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($investments as $investment)
                        <tr style="border-bottom: 1px solid #E5E7EB; transition: background-color 0.2s;">
                            <!-- User -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.9rem;">
                                        {{ substr($investment->user->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <p style="margin: 0; font-weight: 600; color: #1F2937;">{{ $investment->user->full_name ?? $investment->user->name }}</p>
                                        <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem; color: #6B7280;">{{ $investment->user->email }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Plan -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                <span style="padding: 0.5rem 1rem; background: #DBEAFE; color: #1E40AF; border-radius: 8px; font-weight: 600; font-size: 0.875rem;">
                                    {{ $investment->investmentPlan->name ?? 'N/A' }}
                                </span>
                            </td>

                            <!-- Amount -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                <p style="margin: 0; font-weight: 700; color: #1F2937; font-size: 1.1rem;">
                                    ${{ number_format($investment->amount, 2) }}
                                </p>
                            </td>

                            <!-- Expected Profit -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                <p style="margin: 0; font-weight: 700; color: #10B981; font-size: 1.1rem;">
                                    +${{ number_format($investment->expected_profit, 2) }}
                                </p>
                            </td>

                            <!-- Status -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                @if($investment->status === 'active')
                                    <span style="padding: 0.5rem 1rem; background: #D1FAE5; color: #065F46; border-radius: 8px; font-weight: 600; font-size: 0.875rem;">
                                        ✓ Active
                                    </span>
                                @elseif($investment->status === 'completed')
                                    <span style="padding: 0.5rem 1rem; background: #DBEAFE; color: #1E40AF; border-radius: 8px; font-weight: 600; font-size: 0.875rem;">
                                        ✓ Completed
                                    </span>
                                @else
                                    <span style="padding: 0.5rem 1rem; background: #F3F4F6; color: #6B7280; border-radius: 8px; font-weight: 600; font-size: 0.875rem;">
                                        {{ ucfirst($investment->status) }}
                                    </span>
                                @endif
                            </td>

                            <!-- Days Left -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                @if($investment->status === 'active')
                                    <p style="margin: 0; font-weight: 700; color: #F59E0B;">
                                        {{ $investment->remaining_days ?? 0 }} days
                                    </p>
                                @else
                                    <p style="margin: 0; color: #9CA3AF; font-weight: 500;">
                                        Completed
                                    </p>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td style="padding: 1.25rem; vertical-align: middle;">
                                <a href="{{ route('admin.investments.show', $investment) }}" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #2563EB; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.875rem; transition: all 0.3s;">
                                    👁️ View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding: 3rem 2rem; text-align: center; color: #9CA3AF;">
                                <p style="margin: 0; font-size: 1.1rem;">No investments found</p>
                                <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem;">Start by creating an investment plan</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($investments->hasPages())
            <div style="padding: 1.5rem; border-top: 1px solid #E5E7EB;">
                {{ $investments->links() }}
            </div>
        @endif
    </div>

</div>

@endsection