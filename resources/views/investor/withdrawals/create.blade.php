@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Request Withdrawal</h1>

    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <div class="mb-6 p-4 bg-blue-50 rounded">
            <p class="text-gray-700"><strong>Available Balance:</strong> <span class="text-2xl font-bold text-green-600">${{ number_format(auth()->user()->balance, 2) }}</span></p>
        </div>

        <form action="{{ route('investor-investment.withdrawals.store') }}" method="POST">
            @csrf

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Withdrawal Amount</label>
                <input type="number" name="amount" step="0.01" min="0" max="{{ auth()->user()->balance }}" class="w-full px-4 py-2 border rounded-lg @error('amount') border-red-500 @enderror" placeholder="Enter amount" required>
                <p class="text-sm text-gray-500 mt-1">Max: ${{ number_format(auth()->user()->balance, 2) }}</p>
                @error('amount')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Withdrawal Method</label>
                <select name="method" class="w-full px-4 py-2 border rounded-lg @error('method') border-red-500 @enderror" required>
                    <option value="">Select withdrawal method</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="credit_card">Credit Card</option>
                    <option value="paypal">PayPal</option>
                    <option value="bitcoin">Bitcoin</option>
                    <option value="wire_transfer">Wire Transfer</option>
                </select>
                @error('method')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Account Details</label>
                <textarea name="account_details" class="w-full px-4 py-2 border rounded-lg @error('account_details') border-red-500 @enderror" rows="3" placeholder="Enter your account details (e.g., bank account number, PayPal email, Bitcoin address)" required></textarea>
                <p class="text-sm text-gray-500 mt-1">Your information will be kept secure</p>
                @error('account_details')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
            </div>

            <div class="mb-6 p-4 bg-yellow-50 rounded">
                <h3 class="font-semibold text-yellow-900 mb-2">⏱️ Processing Time:</h3>
                <p class="text-sm text-yellow-800">Withdrawals are typically processed within 2-5 business days depending on your bank.</p>
            </div>

            <div class="flex gap-4">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Request Withdrawal</button>
                <a href="{{ route('investor-investment.withdrawals.index') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection