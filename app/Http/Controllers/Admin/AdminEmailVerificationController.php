<?php
// LOCATION: app/Http/Controllers/Admin/AdminEmailVerificationController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;

class AdminEmailVerificationController extends Controller
{
    protected OtpService $otpService;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    // GET /admin/users/{user}/verification-status
    public function status(Request $request, User $user)
    {
        $status = 'pending';
        if ($user->email_verified_at) {
            $status = 'verified';
        } elseif ($user->email_otp_attempts >= 5) {
            $status = 'failed';
        }

        $data = [
            'user_id'             => $user->id,
            'email'                => $user->email,
            'status'               => $status,
            'email_verified_at'    => optional($user->email_verified_at)->toDateTimeString(),
            'otp_expires_at'       => optional($user->email_otp_expires_at)->toDateTimeString(),
            'attempts'             => $user->email_otp_attempts,
        ];

        if ($request->expectsJson()) {
            return response()->json(['verification' => $data]);
        }
        return back()->with('verification', $data);
    }

    // POST /admin/users/{user}/resend-otp
    public function resend(Request $request, User $user)
    {
        if ($user->email_verified_at) {
            return response()->json(['message' => 'User is already verified.'], 422);
        }

        $this->otpService->generateAndSend($user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Verification code resent.']);
        }
        return back()->with('success', 'Verification code resent.');
    }

    // POST /admin/users/{user}/manual-verify
    public function manualVerify(Request $request, User $user)
    {
        $this->otpService->manuallyVerify($user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'User manually verified.']);
        }
        return back()->with('success', 'User manually verified.');
    }
}