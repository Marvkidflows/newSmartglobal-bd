@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Deposit Requests</h1>

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
                    <th class="px-6 py-3 text-left">Payment Method</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deposits as $deposit)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <div class="font-semibold">{{ $deposit->user->full_name }}</div>
                            <div class="text-sm text-gray-500">{{ $deposit->user->email }}</div>
                        </td>
                        <td class="px-6 py-3 font-bold">${{ number_format($deposit->amount, 2) }}</td>
                        <td class="px-6 py-3">{{ $deposit->payment_method }}</td>
                        <td class="px-6 py-3 text-sm">{{ $deposit->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-3">
                            <span class="px-3 py-1 rounded text-sm font-semibold {{ $deposit->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($deposit->status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') }}">
                                {{ ucfirst($deposit->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            <a href="{{ route('admin.deposits.show', $deposit) }}" class="text-blue-600 hover:underline">View</a>
                            @if($deposit->status === 'pending')
                                <form action="{{ route('admin.deposits.approve', $deposit) }}" method="POST" class="inline ml-3">
                                    @csrf
                                    <button type="submit" class="text-green-600 hover:underline">Approve</button>
                                </form>
                                <form action="{{ route('admin.deposits.reject', $deposit) }}" method="POST" class="inline ml-3" onsubmit="let reason = prompt('Rejection reason:'); if(reason) this.reason.value = reason; else return false;">
                                    @csrf
                                    <input type="hidden" name="reason" value="">
                                    <button type="submit" class="text-red-600 hover:underline">Reject</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-3 text-center text-gray-500">No deposits</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $deposits->links() }}
    </div>
</div>
@endsection