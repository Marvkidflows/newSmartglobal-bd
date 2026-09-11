@extends('layouts.app')
@section('content')
<div class="container-fluid px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Investment Plans</h1>
        <a href="{{ route('admin.investment-plans.create') }}" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
            Create Plan
        </a>
    </div>
@if($message = session('success'))
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
        {{ $message }}
    </div>
@endif

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-100 border-b">
            <tr>
                <th class="px-6 py-3 text-left">Name</th>
                <th class="px-6 py-3 text-left">Min Amount</th>
                <th class="px-6 py-3 text-left">Max Amount</th>
                <th class="px-6 py-3 text-left">Profit %</th>
                <th class="px-6 py-3 text-left">Duration</th>
                <th class="px-6 py-3 text-left">Status</th>
                <th class="px-6 py-3 text-left">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($plans as $plan)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-6 py-3 font-semibold">{{ $plan->name }}</td>
                    <td class="px-6 py-3">${{ number_format($plan->min_amount, 2) }}</td>
                    <td class="px-6 py-3">${{ number_format($plan->max_amount, 2) }}</td>
                    <td class="px-6 py-3 font-bold text-green-600">{{ $plan->profit_percentage }}%</td>
                    <td class="px-6 py-3">{{ $plan->duration_months }} months</td>
                    <td class="px-6 py-3">
                        <span class="px-3 py-1 rounded text-sm font-semibold {{ $plan->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ ucfirst($plan->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3">
                        <a href="{{ route('admin.investment-plans.edit', $plan) }}" class="text-blue-600 hover:underline">Edit</a>
                        <form action="{{ route('admin.investment-plans.destroy', $plan) }}" method="POST" class="inline ml-3" onsubmit="return confirm('Delete this plan?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-3 text-center text-gray-500">No investment plans found</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $plans->links() }}
</div>
</div>
@endsection