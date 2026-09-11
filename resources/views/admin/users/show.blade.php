@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('admin.users.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Users</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">{{ $user->full_name ?? $user->name }}</h1>
            <span class="px-3 py-1 rounded text-sm font-semibold {{ $user->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                {{ ucfirst($user->status) }}
            </span>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Email</p>
                <p class="font-semibold">{{ $user->email }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Phone</p>
                <p class="font-semibold">{{ $user->phone ?? 'Not provided' }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Account Balance</p>
                <p class="font-bold text-lg text-green-600">${{ number_format($user->balance, 2) }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Member Since</p>
                <p class="font-semibold">{{ $user->created_at->format('M d, Y') }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">Country</p>
                <p class="font-semibold">{{ $user->country ?? 'Not provided' }}</p>
            </div>
            <div class="p-4 bg-gray-50 rounded">
                <p class="text-gray-600 text-sm">KYC Status</p>
                <span class="px-2 py-1 rounded text-xs font-semibold {{ $user->kyc_status === 'verified' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                    {{ ucfirst($user->kyc_status) }}
                </span>
            </div>
        </div>

        <div class="flex gap-4">
            @if($user->status === 'active')
                <form action="{{ route('admin.users.suspend', $user) }}" method="POST" onsubmit="return confirm('Suspend this user?')">
                    @csrf
                    <button type="submit" class="bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700">Suspend User</button>
                </form>
            @else
                <form action="{{ route('admin.users.activate', $user) }}" method="POST" onsubmit="return confirm('Activate this user?')">
                    @csrf
                    <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">Activate User</button>
                </form>
            @endif
            <a href="{{ route('admin.users.index') }}" class="bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500">Back</a>
        </div>
    </div>
</div>
@endsection