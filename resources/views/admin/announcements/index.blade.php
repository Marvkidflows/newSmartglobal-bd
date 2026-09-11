@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Announcements</h1>
        <a href="{{ route('admin.announcements.create') }}" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Create Announcement</a>
    </div>

    @if($message = session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ $message }}
        </div>
    @endif

    <div class="space-y-4">
        @forelse($announcements as $announcement)
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h3 class="text-xl font-bold mb-2">{{ $announcement->title }}</h3>
                        <p class="text-gray-700 mb-3">{{ Str::limit($announcement->content, 150) }}</p>
                        <div class="flex gap-2 items-center">
                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $announcement->type === 'success' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' }}">
                                {{ ucfirst($announcement->type) }}
                            </span>
                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $announcement->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $announcement->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                    <div class="flex gap-2 ml-4">
                        <a href="{{ route('admin.announcements.edit', $announcement) }}" class="text-blue-600 hover:underline">Edit</a>
                        <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST" class="inline" onsubmit="return confirm('Delete this announcement?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
                No announcements. <a href="{{ route('admin.announcements.create') }}" class="text-blue-600 hover:underline">Create one</a>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $announcements->links() }}
    </div>
</div>
@endsection