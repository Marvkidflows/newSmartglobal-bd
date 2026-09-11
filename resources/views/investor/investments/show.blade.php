@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('investor-investment.investments.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Investments</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <h1 class="text-3xl font-bold mb-6">{{ $investment->investmentPlan->name }} Investment</h1>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm mb-1">Investment Amount</p>
                <p class="text-3xl font-bold text-blue-600">${{ number_format($investment->amount, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm mb-1">Expected Profit</p>
                <p class="text-3xl font-bold text-green-600">+${{ number_format($investment->expected_profit, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm mb-1">Total Return</p>
                <p class="text-3xl font-bold text-purple-600">${{ number_format($investment->total_return, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm mb-1">Status</p>
                <p class="text-lg font-bold">{{ ucfirst($investment->status) }}</p>
            </div>
        </div>

        <div class="mb-6 p-4 border-t border-b">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <p class="text-gray-600 text-sm">Started</p>
                    <p class="font-semibold">{{ $investment->start_date->format('M d, Y') }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Ending</p>
                    <p class="font-semibold">{{ $investment->end_date->format('M d, Y') }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Days Remaining</p>
                    <p class="font-bold text-lg">{{ $investment->remaining_days }} days</p>
                </div>
            </div>
        </div>

        <div class="bg-blue-50 p-4 rounded-lg mb-6">
            <p class="text-sm text-gray-600 mb-2">Plan Details</p>
            <p class="font-semibold">{{ $investment->investmentPlan->name }}</p>
            <p class="text-sm text-gray-600">{{ $investment->investmentPlan->description }}</p>
            <p class="text-sm text-gray-600 mt-2">Profit Rate: <strong>{{ $investment->investmentPlan->profit_percentage }}%</strong></p>
        </div>

        <div class="flex gap-4">
            <a href="{{ route('investor-investment.investments.index') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">Back</a>
        </div>
    </div>
</div>
@endsection