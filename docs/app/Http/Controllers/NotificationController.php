<?php
// LOCATION: app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // GET /investor-investment/notifications
    public function index(Request $request)
    {
        $user          = Auth::user();
        $notifications = $user->notifications()
            ->latest()
            ->take(50)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'title'      => $n->data['title']   ?? 'Notification',
                'message'    => $n->data['message'] ?? $n->data['body'] ?? '',
                'data'       => $n->data,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at->toDateString(),
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'notifications' => $notifications,
                'unread_count'  => $user->unreadNotifications()->count(),
            ]);
        }
        return view('investor.notifications.index', compact('notifications'));
    }

    // POST /investor-investment/notifications/{notification}/read
    public function markAsRead(Request $request, $notificationId)
    {
        $user         = Auth::user();
        $notification = $user->notifications()->find($notificationId);

        if (!$notification) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Notification not found.'], 404);
            }
            return back();
        }

        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Marked as read.']);
        }
        return back();
    }

    // DELETE /investor-investment/notifications/{notification}
    public function destroy(Request $request, $notificationId)
    {
        $user         = Auth::user();
        $notification = $user->notifications()->find($notificationId);

        if ($notification) {
            $notification->delete();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Notification deleted.']);
        }
        return back();
    }
}
