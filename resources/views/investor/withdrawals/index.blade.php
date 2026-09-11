@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">My Withdrawals</h1>
        <a href="{{ route('investor-investment.withdrawals.create') }}" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Request Withdrawal</a>
    </div>

    @if($message = session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ $message }}
        </div>
    @endif

    @if($withdrawals->count())
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left">Amount</th>
                        <th class="px-6 py-3 text-left">Method</th>
                        <th class="px-6 py-3 text-left">Date</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($withdrawals as $withdrawal)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-3 font-bold">${{ number_format($withdrawal->amount, 2) }}</td>
                            <td class="px-6 py-3">{{ $withdrawal->method }}</td>
                            <td class="px-6 py-3 text-sm">{{ $withdrawal->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-3">
                                <span class="px-3 py-1 rounded text-sm font-semibold {{ $withdrawal->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($withdrawal->status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') }}">
                                    {{ ucfirst($withdrawal->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3">
                                <a href="#" class="text-blue-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $withdrawals->links() }}
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
            <p>You haven't made any withdrawal requests yet.</p>
            <a href="{{ route('investor-investment.withdrawals.create') }}" class="text-blue-600 hover:underline mt-2 inline-block">Request withdrawal →</a>
        </div>
    @endif
</div>
@endsection