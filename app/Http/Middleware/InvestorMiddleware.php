<?php
// LOCATION: app/Http/Middleware/InvestorMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InvestorMiddleware
{
    /**
     * Financial endpoints — blocked for both suspended AND frozen accounts.
     * Anything that moves money or creates obligations.
     */
    protected array $financialRoutes = [
        'deposits',
        'withdrawals',
        'investments',
        'withdrawal-pin',
    ];
public function handle(Request $request, Closure $next)
{
    $user = $request->user();

    if (!$user || !$user->isInvestor()) {
        return response()->json(['message' => 'Unauthorized access.'], 403);
    }

    $status = $user->status ?? 'active';

    // ── DEACTIVATED ──
    if ($status === 'deactivated') {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'success' => false,
            'status'  => 'deactivated',
            'message' => 'Your account has been deactivated. Please contact support.',
        ], 401);
    }

    // ── SUSPENDED — messages/support always allowed, everything else read-only ──
    if ($status === 'suspended') {
        $path = $request->path();

        $isSupportRoute = str_contains($path, 'messages');

        $isReadOnlyGet = $request->isMethod('GET') && (
            str_contains($path, 'dashboard') ||
            str_contains($path, 'profile') ||
            str_contains($path, 'notifications') ||
            str_contains($path, 'announcements')
        );

        if (!$isSupportRoute && !$isReadOnlyGet) {
            return response()->json([
                'success' => false,
                'status'  => 'suspended',
                'message' => 'Your account has been temporarily suspended. Please contact support.',
            ], 403);
        }
    }

    // ── FROZEN — block financial endpoints only ──
    if ($status === 'frozen') {
        $path = $request->path();
        foreach ($this->financialRoutes as $route) {
            if (str_contains($path, $route) && !$request->isMethod('GET')) {
                return response()->json([
                    'success' => false,
                    'status'  => 'frozen',
                    'message' => 'Your account has been frozen. Financial operations are disabled.',
                ], 403);
            }
        }
    }

    return $next($request);
}
}