@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('admin.deposits.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Deposits</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">Deposit Details</h1>

        <div class="mb-6 p-4 bg-gray-50 rounded">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-gray-600 text-sm">User</p>
                    <p class="text-lg font-semibold">{{ $deposit->user->full_name }}</p>
                    <p class="text-sm text-gray-500">{{ $deposit->user->email }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Amount</p>
                    <p class="text-2xl font-bold text-green-600">${{ number_format($deposit->amount, 2) }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Payment Method</p>
                    <p class="text-lg font-semibold">{{ $deposit->payment_method }}</p>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Status</p>
                    <span class="px-3 py-1 rounded text-sm font-semibold {{ $deposit->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($deposit->status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') }}">
                        {{ ucfirst($deposit->status) }}
                    </span>
                </div>
                <div>
                    <p class="text-gray-600 text-sm">Date</p>
                    <p class="text-lg font-semibold">{{ $deposit->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </div>

        @if($deposit->proof_image)
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3">Payment Proof</h3>
                <img src="{{ Storage::url($deposit->proof_image) }}" alt="Payment Proof" class="max-w-md rounded border">
            </div>
        @endif

        @if($deposit->status === 'pending')
            <div class="flex gap-4">
                <form action="{{ route('admin.deposits.approve', $deposit) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">Approve Deposit</button>
                </form>
                
                <form action="{{ route('admin.deposits.reject', $deposit) }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700" onclick="let reason = prompt('Rejection reason:'); if(reason) this.form.reason.value = reason; else return false;">Reject Deposit</button>
                </form>
            </div>
        @else
            <div class="p-4 bg-gray-100 rounded">
                <p class="text-gray-600"><strong>Processed:</strong> {{ $deposit->processed_at->format('M d, Y H:i') }}</p>
                @if($deposit->admin_notes)
                    <p class="text-gray-600 mt-2"><strong>Notes:</strong> {{ $deposit->admin_notes }}</p>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection