@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Manage Users</h1>

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
                    <th class="px-6 py-3 text-left">Email</th>
                    <th class="px-6 py-3 text-left">Balance</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Joined</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <div class="font-semibold">{{ $user->full_name ?? $user->name }}</div>
                        </td>
                        <td class="px-6 py-3">{{ $user->email }}</td>
                        <td class="px-6 py-3 font-bold">${{ number_format($user->balance, 2) }}</td>
                        <td class="px-6 py-3">
                            <span class="px-3 py-1 rounded text-sm font-semibold {{ $user->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ ucfirst($user->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-sm">{{ $user->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-3">
                            <a href="{{ route('admin.users.show', $user) }}" class="text-blue-600 hover:underline">View</a>
                            @if($user->status === 'active')
                                <form action="{{ route('admin.users.suspend', $user) }}" method="POST" class="inline ml-3" onsubmit="return confirm('Suspend this user?')">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:underline">Suspend</button>
                                </form>
                            @else
                                <form action="{{ route('admin.users.activate', $user) }}" method="POST" class="inline ml-3" onsubmit="return confirm('Activate this user?')">
                                    @csrf
                                    <button type="submit" class="text-green-600 hover:underline">Activate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-3 text-center text-gray-500">No users found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
@endsection