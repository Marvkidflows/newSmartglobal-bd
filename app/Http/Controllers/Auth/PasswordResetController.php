<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class PasswordResetController extends Controller
{
    /**
     * POST /api/forgot-password
     * Sends a reset link if the email exists.
     * Always returns the same generic response (no email enumeration).
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid email address.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Laravel's Password broker handles token generation + sending
        // the notification (via our custom ResetPasswordNotification below).
        // A delivery failure must not change the response either: a 500 only for existing accounts would reveal them.
        try {
            Password::sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            \Log::warning('Password reset link could not be sent: ' . get_class($e));
        }

        // Always return the same generic response regardless of outcome,
        // to avoid leaking whether an email exists in the system.
        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent.',
        ], 200);
    }

    /**
     * POST /api/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => array_merge(['required', 'confirmed'], \App\Support\PasswordPolicy::rules()),   // D-12
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                ])->setRememberToken(\Illuminate\Support\Str::random(60));

                $user->save();

                // D-02: a reset ends EVERY existing session, so a stolen token cannot outlive the password it was issued under.
                \App\Support\AuthTokens::revokeAll($user);

                event(new PasswordReset($user));
            }
        );

        switch ($status) {
            case Password::PASSWORD_RESET:
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset successful.',
                ], 200);

            // D-12: an unknown e-mail and a bad/expired token get the SAME answer (status and text), so this endpoint cannot be used to find out
            // which addresses have accounts.
            case Password::INVALID_USER:
            case Password::INVALID_TOKEN:
                return response()->json([
                    'success' => false,
                    'message' => 'This password reset link is invalid or has expired. Please request a new one.',
                ], 400);

            case Password::RESET_THROTTLED:
                return response()->json([
                    'success' => false,
                    'message' => 'Please wait before retrying.',
                ], 429);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'This password reset link is invalid or has expired. Please request a new one.',
                ], 400);
        }
    }
}

