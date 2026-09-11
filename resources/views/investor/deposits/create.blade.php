@extends('layouts.app')
@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Make a Deposit</h1>
<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form action="{{ route('investor.deposits.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Amount ($)</label>
            <input type="number" name="amount" step="0.01" min="10" class="w-full px-4 py-2 border rounded-lg @error('amount') border-red-500 @enderror" required>
            @error('amount')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Payment Method</label>
            <select name="payment_method" class="w-full px-4 py-2 border rounded-lg @error('payment_method') border-red-500 @enderror" required>
                <option>Bank Transfer</option>
                <option>Cryptocurrency</option>
                <option>Credit Card</option>
                <option>Other</option>
            </select>
            @error('payment_method')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Proof of Payment (Screenshot)</label>
            <input type="file" name="proof_image" accept="image/*" class="w-full px-4 py-2 border rounded-lg @error('proof_image') border-red-500 @enderror" required>
            @error('proof_image')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
        </div>

        <div class="flex gap-4">
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700 font-semibold">
                Submit Deposit
            </button>
            <a href="{{ route('investor.dashboard') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">
                Cancel
            </a>
        </div>
    </form>
</div>
</div>
@endsection