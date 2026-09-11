@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">My Investments</h1>
        <a href="{{ route('investor-investment.investments.plans') }}" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Browse Plans</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">Active Investments</p>
            <p class="text-3xl font-bold text-blue-600">{{ $activeInvestments }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">Total Invested</p>
            <p class="text-3xl font-bold text-green-600">${{ number_format($totalInvested, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">Expected Profit</p>
            <p class="text-3xl font-bold text-purple-600">${{ number_format($totalProfit, 2) }}</p>
        </div>
    </div>

    @if($investments->count())
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left">Plan</th>
                        <th class="px-6 py-3 text-left">Amount</th>
                        <th class="px-6 py-3 text-left">Profit</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Days Remaining</th>
                        <th class="px-6 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($investments as $investment)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <div class="font-semibold">{{ $investment->investmentPlan->name }}</div>
                                <div class="text-sm text-gray-500">Started {{ $investment->created_at->format('M d, Y') }}</div>
                            </td>
                            <td class="px-6 py-3 font-bold">${{ number_format($investment->amount, 2) }}</td>
                            <td class="px-6 py-3 text-green-600 font-bold">+${{ number_format($investment->expected_profit, 2) }}</td>
                            <td class="px-6 py-3">
                                <span class="px-3 py-1 rounded text-sm font-semibold {{ $investment->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ ucfirst($investment->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3">
                                @if($investment->status === 'active')
                                    <span class="font-semibold">{{ $investment->remaining_days }} days</span>
                                @else
                                    <span class="text-gray-500">Completed</span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <a href="{{ route('investor-investment.investments.show', $investment) }}" class="text-blue-600 hover:underline">View Details</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
            <p>You don't have any investments yet.</p>
            <a href="{{ route('investor-investment.investments.plans') }}" class="text-blue-600 hover:underline mt-2 inline-block">Start investing →</a>
        </div>
    @endif
</div>
@endsection