@extends('layouts.app')
@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Create Investment Plan</h1>
<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form action="{{ route('admin.investment-plans.store') }}" method="POST">
        @csrf

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Plan Name</label>
            <input type="text" name="name" class="w-full px-4 py-2 border rounded-lg @error('name') border-red-500 @enderror" required>
            @error('name')<span class="text-red-500 text-sm">{{ $message }}</span>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Description</label>
            <textarea name="description" class="w-full px-4 py-2 border rounded-lg" rows="3"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Minimum Amount</label>
                <input type="number" name="min_amount" step="0.01" class="w-full px-4 py-2 border rounded-lg" required>
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Maximum Amount</label>
                <input type="number" name="max_amount" step="0.01" class="w-full px-4 py-2 border rounded-lg" required>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Profit Percentage (%)</label>
                <input type="number" name="profit_percentage" step="0.01" min="0" max="100" class="w-full px-4 py-2 border rounded-lg" required>
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Duration (Months)</label>
                <input type="number" name="duration_months" min="1" class="w-full px-4 py-2 border rounded-lg" required>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 font-semibold mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 border rounded-lg">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Create Plan</button>
            <a href="{{ route('admin.investment-plans.index') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">Cancel</a>
        </div>
    </form>
</div>
</div>
@endsection
