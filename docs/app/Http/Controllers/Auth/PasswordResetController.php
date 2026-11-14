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
        Password::sendResetLink(
            $request->only('email')
        );

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
            'password'              => ['required', 'string', 'confirmed', Rules\Password::min(8)],
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

                event(new PasswordReset($user));
            }
        );

        switch ($status) {
            case Password::PASSWORD_RESET:
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset successful.',
                ], 200);

            case Password::INVALID_USER:
                return response()->json([
                    'success' => false,
                    'message' => 'We could not find a user with that email address.',
                ], 404);

            case Password::INVALID_TOKEN:
                return response()->json([
                    'success' => false,
                    'message' => 'This password reset link is invalid.',
                ], 400);

            case Password::RESET_THROTTLED:
                return response()->json([
                    'success' => false,
                    'message' => 'Please wait before retrying.',
                ], 429);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to reset password. The link may have expired.',
                ], 400);
        }
    }
}

