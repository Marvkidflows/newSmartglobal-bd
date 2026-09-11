@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('investor-investment.investments.plans') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Plans</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">Invest in {{ $plan->name }}</h1>

        <div class="p-4 bg-blue-50 rounded mb-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-gray-600 text-sm">Minimum Amount</p>
                    <p class="font-bold text-lg">${{ number_format($plan->min_amount, 2) }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Maximum Amount</p>
                    <p class="font-bold text-lg">${{ number_format($plan->max_amount, 2) }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Profit Percentage</p>
                    <p class="font-bold text-lg text-green-600">{{ $plan->profit_percentage }}%</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Duration</p>
                    <p class="font-bold text-lg">{{ $plan->duration_months }} months</p>
                </div>
            </div>
        </div>

        <form action="{{ route('investor-investment.investments.store') }}" method="POST">
            @csrf
            <input type="hidden" name="investment_plan_id" value="{{ $plan->id }}">

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Investment Amount</label>
                <input type="number" name="amount" step="0.01" min="{{ $plan->min_amount }}" max="{{ $plan->max_amount }}" placeholder="Enter amount" class="w-full px-4 py-2 border rounded-lg @error('amount') border-red-500 @enderror" required>
                @error('amount')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
            </div>

            <div class="mb-6 p-4 bg-gray-50 rounded">
                <h3 class="font-semibold mb-2">Investment Summary</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-gray-600 text-sm">Amount</p>
                        <p class="font-bold" id="summaryAmount">$0.00</p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Expected Profit</p>
                        <p class="font-bold text-green-600" id="summaryProfit">$0.00</p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Total Return</p>
                        <p class="font-bold text-blue-600" id="summaryReturn">$0.00</p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Maturity Date</p>
                        <p class="font-bold" id="summaryDate">-</p>
                    </div>
                </div>
            </div>

            <div class="flex gap-4">
                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">Confirm Investment</button>
                <a href="{{ route('investor-investment.investments.plans') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelector('input[name="amount"]').addEventListener('input', function(e) {
    const amount = parseFloat(e.target.value) || 0;
    const profitPercentage = {{ $plan->profit_percentage }};
    const profit = amount * (profitPercentage / 100);
    const total = amount + profit;
    
    document.getElementById('summaryAmount').textContent = '$' + amount.toFixed(2);
    document.getElementById('summaryProfit').textContent = '$' + profit.toFixed(2);
    document.getElementById('summaryReturn').textContent = '$' + total.toFixed(2);
    
    const today = new Date();
    const maturityDate = new Date(today.setMonth(today.getMonth() + {{ $plan->duration_months }}));
    document.getElementById('summaryDate').textContent = maturityDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
});
</script>
@endsection