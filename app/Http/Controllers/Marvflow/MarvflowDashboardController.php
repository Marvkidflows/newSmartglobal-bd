<?php
// LOCATION: app/Http/Controllers/Marvflow/MarvflowDashboardController.php

namespace App\Http\Controllers\Marvflow;

use App\Http\Controllers\Controller;
use App\Models\TeamRequest;
use Illuminate\Http\Request;

class MarvflowDashboardController extends Controller
{
    // GET /marvflow/dashboard
    public function index(Request $request)
    {
        $stats = [
            'total_requests'   => TeamRequest::count(),
            'new_requests'     => TeamRequest::where('status', 'new')->count(),
            'urgent_requests'  => TeamRequest::where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'in_progress'      => TeamRequest::whereIn('status', ['acknowledged', 'in_progress', 'waiting_for_info'])->count(),
            'resolved'         => TeamRequest::whereIn('status', ['resolved', 'closed'])->count(),
        ];

        $recent = TeamRequest::with(['sender:id,name,role', 'assignee:id,name'])
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (TeamRequest $r) => [
                'id'          => $r->id,
                'display_id'  => $r->displayId(),
                'subject'     => $r->subject,
                'sender_name' => $r->sender->name ?? 'Unknown',
                'priority'    => $r->priority,
                'status'      => $r->status,
                'assigned_to' => $r->assignee?->name,
                'created_at'  => $r->created_at->toIso8601String(),
            ]);

        return response()->json([
            'stats'  => $stats,
            'recent' => $recent,
        ]);
    }
}
