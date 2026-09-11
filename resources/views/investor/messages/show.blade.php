@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <a href="{{ route('investor-investment.messages.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">← Back to Messages</a>
    
    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <div class="mb-6 pb-4 border-b">
            <h1 class="text-2xl font-bold">{{ $message->subject }}</h1>
            <p class="text-sm text-gray-500">Sent: {{ $message->created_at->format('M d, Y H:i') }}</p>
        </div>

        <div class="mb-6 p-4 bg-gray-50 rounded">
            <p class="whitespace-pre-wrap">{{ $message->message }}</p>
        </div>

        @if($message->admin_reply)
            <div class="mb-6 p-4 bg-green-50 rounded border-l-4 border-green-600">
                <p class="text-sm text-gray-600 mb-2"><strong>Admin Response:</strong></p>
                <p class="whitespace-pre-wrap">{{ $message->admin_reply }}</p>
                <p class="text-xs text-gray-500 mt-2">Replied: {{ $message->replied_at->format('M d, Y H:i') }}</p>
            </div>
        @else
            <div class="p-4 bg-yellow-50 rounded">
                <p class="text-yellow-800"><strong>Status:</strong> Awaiting admin response...</p>
            </div>
        @endif

        <a href="{{ route('investor-investment.messages.index') }}" class="inline-block bg-gray-400 text-white px-6 py-2 rounded hover:bg-gray-500 mt-6">Back to Messages</a>
    </div>
</div>
@endsection