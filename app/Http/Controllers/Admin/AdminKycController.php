<?php
// LOCATION: app/Http/Controllers/Admin/AdminKycController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminKycController extends Controller
{
    // GET /admin/kyc
    public function index(Request $request)
    {
        $query = User::where('role', 'investor')
            ->whereNotNull('id_document_path');

        if ($request->filled('status')) {
            $query->where('kyc_status', $request->status);
        }

        $submissions = $query->latest('updated_at')->get()->map(function ($u) {
            return [
                'id'              => $u->id,
                'name'            => $u->name ?? $u->full_name,
                'email'           => $u->email,
                'id_type'         => $u->id_type,
                'id_number'       => $u->id_number,
                'kyc_status'      => $u->kyc_status_safe,
                'submitted_at'    => optional($u->updated_at)->toDateTimeString(),
                'kyc_verified_at' => optional($u->kyc_verified_at)->toDateTimeString(),
            ];
        });

        if ($request->expectsJson()) {
            return response()->json([
                'submissions' => $submissions
            ]);
        }

        return view('admin.kyc.index', compact('submissions'));
    }

    // GET /admin/kyc/{user}
    public function show(Request $request, User $user)
    {
        $data = [
            'id'                   => $user->id,
            'name'                 => $user->name ?? $user->full_name,
            'email'                => $user->email,
            'phone'                => $user->phone,
            'country'              => $user->country,
            'address'              => $user->address,
            'city'                 => $user->city,
            'state'                => $user->state,
            'date_of_birth'        => optional($user->date_of_birth)->toDateString(),
            'id_type'              => $user->id_type,
            'id_number'            => $user->id_number,

            // Cloudinary URLs
            'id_document_url'      => $user->id_document_path,
            'selfie_url'           => $user->selfie_path,

            'kyc_status'           => $user->kyc_status_safe,
            'kyc_verified_at'      => optional($user->kyc_verified_at)->toDateTimeString(),
            'kyc_rejection_reason' => $user->kyc_rejection_reason,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'submission' => $data
            ]);
        }

        return view('admin.kyc.show', compact('user'));
    }



    // POST /admin/kyc/{user}/approve
    public function approve(Request $request, User $user)
    {
        if (!$user->id_document_path) {
            return response()->json(['message' => 'This investor has not submitted any verification documents.'], 422);
        }

        $user->update([
            'kyc_status'           => 'approved',
            'kyc_verified'         => true,
            'kyc_verified_at'      => now(),
            'kyc_rejection_reason' => null,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Investor verified successfully.', 'kyc_status' => 'approved']);
        }
        return back()->with('success', 'Investor verified.');
    }

    // POST /admin/kyc/{user}/reject
    // Also used for "Request Resubmission" — frontend distinguishes via the reason text it sends.
    public function reject(Request $request, User $user)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user->update([
            'kyc_status'           => 'rejected',
            'kyc_verified'         => false,
            'kyc_verified_at'      => null,
            'kyc_rejection_reason' => $validated['reason'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Verification rejected.', 'kyc_status' => 'rejected']);
        }
        return back()->with('success', 'Verification rejected.');
    }
}