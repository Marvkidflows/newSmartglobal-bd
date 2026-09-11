@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Withdrawal Requests</h1>

    @if($message = session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ $message }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100 border-b">
                <tr>
                    <th class="px-6 py-3 text-left">User</th>
                    <th class="px-6 py-3 text-left">Amount</th>
                    <th class="px-6 py-3 text-left">Method</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($withdrawals as $withdrawal)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <div class="font-semibold">{{ $withdrawal->user->full_name }}</div>
                            <div class="text-sm text-gray-500">{{ $withdrawal->user->email }}</div>
                        </td>
                        <td class="px-6 py-3 font-bold">${{ number_format($withdrawal->amount, 2) }}</td>
                        <td class="px-6 py-3">{{ $withdrawal->method }}</td>
                        <td class="px-6 py-3 text-sm">{{ $withdrawal->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-3">
                            <span class="px-3 py-1 rounded text-sm font-semibold {{ $withdrawal->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($withdrawal->status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') }}">
                                {{ ucfirst($withdrawal->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            @if($withdrawal->status === 'pending')
                                <form action="{{ route('admin.withdrawals.approve', $withdrawal) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="text-green-600 hover:underline">Approve</button>
                                </form>
                                <form action="{{ route('admin.withdrawals.reject', $withdrawal) }}" method="POST" class="inline ml-3" onsubmit="let reason = prompt('Rejection reason:'); if(reason) this.reason.value = reason; else return false;">
                                    @csrf
                                    <input type="hidden" name="reason" value="">
                                    <button type="submit" class="text-red-600 hover:underline">Reject</button>
                                </form>
                            @else
                                <span class="text-gray-500">Processed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-3 text-center text-gray-500">No withdrawal requests</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $withdrawals->links() }}
    </div>
</div>
@endsection