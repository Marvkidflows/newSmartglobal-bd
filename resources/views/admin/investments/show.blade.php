@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('admin.investments.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Investments</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <h1 class="text-3xl font-bold mb-6">Investment Details</h1>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">User</p>
                <p class="font-semibold">{{ $investment->user->full_name }}</p>
                <p class="text-sm text-gray-500">{{ $investment->user->email }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Plan</p>
                <p class="font-semibold">{{ $investment->investmentPlan->name }}</p>
                <p class="text-sm text-gray-500">{{ $investment->investmentPlan->profit_percentage }}% profit</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Amount Invested</p>
                <p class="text-2xl font-bold text-blue-600">${{ number_format($investment->amount, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Expected Profit</p>
                <p class="text-2xl font-bold text-green-600">+${{ number_format($investment->expected_profit, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Total Return</p>
                <p class="text-2xl font-bold text-purple-600">${{ number_format($investment->total_return, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Status</p>
                <span class="px-3 py-1 rounded text-sm font-semibold {{ $investment->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                    {{ ucfirst($investment->status) }}
                </span>
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

        @if($investment->status === 'active')
            <form action="{{ route('admin.investments.complete', $investment) }}" method="POST" onsubmit="return confirm('Mark investment as completed?')">
                @csrf
                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">Mark as Completed</button>
            </form>
        @else
            <div class="p-4 bg-green-50 rounded">
                <p class="text-green-800"><strong>Status:</strong> This investment has been completed.</p>
            </div>
        @endif
    </div>
</div>
@endsection