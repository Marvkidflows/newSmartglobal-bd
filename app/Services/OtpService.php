<?php
// LOCATION: app/Services/OtpService.php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OtpService
{
    protected const EXPIRY_MINUTES = 10;
    protected const MAX_ATTEMPTS   = 5;

    /**
     * Generate a fresh OTP for the user, store it, and email it.
     */
    public function generateAndSend(User $user): void
    {
        $otp = (string) random_int(100000, 999999);

        $user->forceFill([
            'email_otp'            => $otp,
            'email_otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'email_otp_attempts'   => 0,
        ])->save();

        try {
            Mail::mailer('brevo')->to($user->email)->send(new OtpMail($otp, $user->full_name ?? $user->name));
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Attempt to verify a submitted OTP against the user's stored code.
     *
     * @return array{success: bool, message: string}
     */
    public function verify(User $user, string $submittedOtp): array
    {
        if ($user->email_verified_at) {
            return ['success' => true, 'message' => 'Email already verified.'];
        }

        if (!$user->email_otp || !$user->email_otp_expires_at) {
            return ['success' => false, 'message' => 'No verification code found. Please request a new one.'];
        }

        if (now()->greaterThan($user->email_otp_expires_at)) {
            return ['success' => false, 'message' => 'This code has expired. Please request a new one.'];
        }

        if ($user->email_otp_attempts >= self::MAX_ATTEMPTS) {
            return ['success' => false, 'message' => 'Too many failed attempts. Please request a new code.'];
        }

        if (!hash_equals($user->email_otp, $submittedOtp)) {
            $user->increment('email_otp_attempts');
            return ['success' => false, 'message' => 'Incorrect code. Please try again.'];
        }

        $user->forceFill([
            'email_verified_at'    => now(),
            'email_otp'            => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts'   => 0,
        ])->save();

        return ['success' => true, 'message' => 'Email verified successfully.'];
    }

    /**
     * Admin override: mark a user's email as verified without an OTP.
     */
    public function manuallyVerify(User $user): void
    {
        $user->forceFill([
            'email_verified_at'    => now(),
            'email_otp'            => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts'   => 0,
        ])->save();
    }
}