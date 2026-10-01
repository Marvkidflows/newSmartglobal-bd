<?php
// LOCATION: app/Http/Controllers/Marvflow/MarvflowTeamController.php

namespace App\Http\Controllers\Marvflow;

use App\Http\Controllers\Controller;
use App\Models\TeamRequest;
use App\Models\User;
use Illuminate\Http\Request;

class MarvflowTeamController extends Controller
{
    // GET /marvflow/team
    public function index(Request $request)
    {
        $members = User::whereIn('role', ['marvflow_member', 'marvflow_lead'])
            ->withCount([
                'assignedTeamRequests as assigned_count',
                'assignedTeamRequests as open_count' => fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']),
            ])
            ->get()
            ->map(fn (User $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'role'          => $u->role,
                'assigned_count' => $u->assigned_count,
                'open_count'     => $u->open_count,
            ]);

        return response()->json(['members' => $members]);
    }
}
