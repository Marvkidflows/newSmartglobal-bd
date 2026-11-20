<?php
// LOCATION: app/Support/PasswordPolicy.php   (NEW FILE — D-12)
//
// One definition of "acceptable new password" for reset, registration and every password change. Defaults equal the previous behaviour (8 characters)
// so nothing changes on release; Management decision D24 (length / complexity / breach check) is applied by setting config/ssi_auth.php values.
// A maximum is enforced because bcrypt silently ignores everything after 72 bytes and a multi-megabyte "password" is a cheap denial-of-service.

namespace App\Support;

class PasswordPolicy
{
    /** Validation rules for a NEW password field (add 'confirmed' / 'different:...' at the call site). */
    public static function rules(): array
    {
        $rules = ['string', 'min:' . self::minLength(), 'max:' . self::maxLength()];
        if ((bool) config('ssi_auth.password_require_mixed', false)) {
            $rules[] = \Illuminate\Validation\Rules\Password::min(self::minLength())->mixedCase()->numbers();
        }
        if ((bool) config('ssi_auth.password_check_breached', false)) {
            $rules[] = \Illuminate\Validation\Rules\Password::min(self::minLength())->uncompromised();   // k-anonymity lookup; needs outbound HTTPS
        }
        return $rules;
    }

    public static function minLength(): int
    {
        return max(8, (int) config('ssi_auth.password_min_length', 8));          // never below the existing floor of 8
    }

    public static function maxLength(): int
    {
        return max(self::minLength(), (int) config('ssi_auth.password_max_length', 128));
    }
}
