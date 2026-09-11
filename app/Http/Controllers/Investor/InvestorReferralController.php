<?php
// LOCATION: app/Http/Controllers/Investor/InvestorReferralController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvestorReferralController extends Controller
{
    // GET /investor-investment/investor/referrals
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get all users referred by this investor
        $referrals = User::where('referred_by', $user->id)
            ->latest()
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'name'       => $r->name ?? $r->full_name,
                'email'      => $r->email,
                'status'     => $r->status ?? 'active',
                'bonus'      => 0, // Update this if you track referral bonuses
                'created_at' => $r->created_at->toDateString(),
            ]);

        $data = [
            'referral_code' => $user->referral_code ?? null,
            'referral_link' => url('/register?ref=' . ($user->referral_code ?? '')),
            'total_referrals'  => $referrals->count(),
            'active_referrals' => $referrals->where('status', 'active')->count(),
            'total_bonus'      => 0, // Update if you track bonuses
            'referrals'        => $referrals->values(),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.referrals.index', $data);
    }
}
