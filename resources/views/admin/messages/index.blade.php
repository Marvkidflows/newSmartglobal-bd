@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">User Messages</h1>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100 border-b">
                <tr>
                    <th class="px-6 py-3 text-left">From</th>
                    <th class="px-6 py-3 text-left">Subject</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <div class="font-semibold">{{ $message->user->full_name }}</div>
                            <div class="text-sm text-gray-500">{{ $message->user->email }}</div>
                        </td>
                        <td class="px-6 py-3 font-semibold">{{ $message->subject }}</td>
                        <td class="px-6 py-3 text-sm">{{ $message->created_at->format('M d, Y H:i') }}</td>
                        <td class="px-6 py-3">
                            <span class="px-3 py-1 rounded text-sm font-semibold {{ $message->status === 'unread' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                                {{ ucfirst($message->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            <a href="{{ route('admin.messages.show', $message) }}" class="text-blue-600 hover:underline">View</a>
                            <form action="{{ route('admin.messages.destroy', $message) }}" method="POST" class="inline ml-3" onsubmit="return confirm('Delete this message?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-3 text-center text-gray-500">No messages</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $messages->links() }}
    </div>
</div>
@endsection