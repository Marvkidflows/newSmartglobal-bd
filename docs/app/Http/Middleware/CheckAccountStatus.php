<?php
// LOCATION: app/Http/Middleware/CheckAccountStatus.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAccountStatus
{
    /**
     * Path fragments for endpoints suspended investors cannot WRITE to.
     * Matches spec: deposits, withdrawals, transfers, referrals, KYC, profile updates.
     * Reading (GET) these same endpoints is still allowed.
     */
    protected array $suspendedBlockedRoutes = [
        'investor/deposits',
        'investor/withdrawals',
        'investor/investments',
        'investor/profile',       // covers PUT /investor/profile and POST /investor/profile/kyc
        'investor/withdrawal-pin',
    ];

    /**
     * Path fragments frozen investors cannot WRITE to — financial only.
     */
    protected array $frozenBlockedRoutes = [
        'investor/deposits',
        'investor/withdrawals',
        'investor/investments',
        'investor/withdrawal-pin',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) return $next($request);

        // ── DEACTIVATED — kill the token and block immediately, any method ────
        if ($user->status === 'deactivated') {
            $user->currentAccessToken()?->delete();
            return response()->json([
                'success' => false,
                'status'  => 'deactivated',
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 401);
        }

        // ── SUSPENDED — reads always allowed; blocked writes only ─────────────
        if ($user->status === 'suspended' && $user->role !== 'admin') {
            $isBlockedWrite = !$request->isMethod('GET')
                && collect($this->suspendedBlockedRoutes)
                    ->some(fn($route) => $request->is("api/investor-investment/{$route}*"));

            if ($isBlockedWrite) {
                return response()->json([
                    'success' => false,
                    'status'  => 'suspended',
                    'message' => 'Your account is suspended. This action is currently disabled — please contact support.',
                ], 403);
            }
        }

        // ── FROZEN — reads always allowed; blocked financial writes only ───────
        if ($user->status === 'frozen' && $user->role !== 'admin') {
            $isBlockedWrite = !$request->isMethod('GET')
                && collect($this->frozenBlockedRoutes)
                    ->some(fn($route) => $request->is("api/investor-investment/{$route}*"));

            if ($isBlockedWrite) {
                return response()->json([
                    'success' => false,
                    'status'  => 'frozen',
                    'message' => 'Your account is frozen. Financial operations are disabled until further notice.',
                ], 403);
            }
        }

        return $next($request);
    }
}