@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('admin.messages.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Messages</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <div class="mb-6 pb-4 border-b">
            <h1 class="text-2xl font-bold">{{ $message->subject }}</h1>
            <p class="text-gray-600">From: <strong>{{ $message->user->full_name }}</strong> ({{ $message->user->email }})</p>
            <p class="text-sm text-gray-500">{{ $message->created_at->format('M d, Y H:i') }}</p>
        </div>

        <div class="mb-6 p-4 bg-gray-50 rounded">
            <p class="whitespace-pre-wrap">{{ $message->message }}</p>
        </div>

        @if($message->admin_reply)
            <div class="mb-6 p-4 bg-blue-50 rounded border-l-4 border-blue-600">
                <p class="text-sm text-gray-600 mb-2"><strong>Your Reply:</strong></p>
                <p class="whitespace-pre-wrap">{{ $message->admin_reply }}</p>
            </div>
        @else
            <form action="{{ route('admin.messages.reply', $message) }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Your Reply</label>
                    <textarea name="reply" class="w-full px-4 py-2 border rounded-lg" rows="5" required></textarea>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Send Reply</button>
            </form>
        @endif
    </div>
</div>
@endsection