@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">My Messages</h1>
        <a href="{{ route('investor-investment.messages.create') }}" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Send Message</a>
    </div>

    @if($message = session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ $message }}
        </div>
    @endif

    @if($messages->count())
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left">Subject</th>
                        <th class="px-6 py-3 text-left">Date Sent</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Reply Date</th>
                        <th class="px-6 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($messages as $msg)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-3 font-semibold">{{ $msg->subject }}</td>
                            <td class="px-6 py-3 text-sm">{{ $msg->created_at->format('M d, Y H:i') }}</td>
                            <td class="px-6 py-3">
                                <span class="px-3 py-1 rounded text-sm font-semibold {{ $msg->status === 'unread' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                                    {{ $msg->admin_reply ? 'Replied' : 'Pending' }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                @if($msg->replied_at)
                                    {{ $msg->replied_at->format('M d, Y H:i') }}
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <a href="{{ route('investor-investment.messages.show', $msg) }}" class="text-blue-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $messages->links() }}
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
            <p>No messages yet. <a href="{{ route('investor-investment.messages.create') }}" class="text-blue-600 hover:underline">Send a message →</a></p>
        </div>
    @endif
</div>
@endsection