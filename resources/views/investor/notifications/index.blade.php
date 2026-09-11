@extends('layouts.dashboard')

@section('title', 'Notifications')

@section('content')
    <div style="margin-bottom: 2rem;">
        <h2 style="font-size: 2rem; font-weight: 700; color: #1F2937; margin-bottom: 0.5rem; font-family: 'Crimson Pro', serif;">
            Notifications
        </h2>
        <p style="color: #6B7280;">Stay updated with your investments and messages</p>
    </div>

    <!-- Notifications List -->
    <div class="card">
        <div class="card-body">
            @if($notifications->count() > 0)
                @foreach($notifications as $notification)
                    <div style="padding: 1rem; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
                        <div style="flex: 1;">
                            <h4 style="font-weight: 600; color: #1F2937; margin-bottom: 0.25rem;">
                                {{ $notification->title }}
                            </h4>
                            <p style="font-size: 0.875rem; color: #6B7280; margin: 0 0 0.5rem 0;">
                                {{ $notification->message }}
                            </p>
                            <p style="font-size: 0.75rem; color: #9CA3AF; margin: 0;">
                                {{ $notification->created_at->diffForHumans() }}
                            </p>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            @if(!$notification->read)
                                <form action="{{ route('investor-investment.notifications.read', $notification) }}" method="POST" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="badge badge-info" style="border: none; cursor: pointer;">
                                        Mark as read
                                    </button>
                                </form>
                            @endif
                            <form action="{{ route('investor-investment.notifications.destroy', $notification) }}" method="POST" style="margin: 0;" onsubmit="return confirm('Delete this notification?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="background: #FEE2E2; color: #991B1B; border: none; padding: 0.375rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach

                <!-- Pagination -->
                <div style="margin-top: 2rem;">
                    {{ $notifications->links() }}
                </div>
            @else
                <div style="text-align: center; padding: 3rem 2rem; color: #9CA3AF;">
                    <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No notifications yet</p>
                    <p style="font-size: 0.875rem;">Check back later for updates on your investments</p>
                </div>
            @endif
        </div>
    </div>
@endsection