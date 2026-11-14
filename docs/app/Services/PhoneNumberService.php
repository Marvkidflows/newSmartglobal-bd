<?php
// LOCATION: app/Services/PhoneNumberService.php
//
// Phase 2 fix — centralizes phone/country handling so registration,
// profile edits, and anywhere else a phone number is captured all use the
// SAME real validation instead of the old `required|string|max:20` rule
// that let a single digit through.
//
// Design: the client only needs to send two things —
//   - country_iso2   (2-letter region code, e.g. "NG", "US", "GB")
//   - phone           (the digits the investor typed, national format)
//
// Everything else (dial code, display country name, and whether the
// number is actually a valid, real phone number for that country) is
// derived and verified HERE, server-side, using libphonenumber (Google's
// library, via propaganistas/laravel-phone + giggsey/libphonenumber-for-php)
// and Symfony's maintained country-name data (symfony/intl). The frontend
// is never trusted to supply the dial code or the country display name.

namespace App\Services;

use Illuminate\Validation\ValidationException;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Symfony\Component\Intl\Countries;

class PhoneNumberService
{
    /**
     * Validate + normalize a (country_iso2, raw phone) pair.
     *
     * @throws ValidationException if the ISO2 code is unknown or the
     *         number is not a valid, real phone number for that country.
     *
     * @return array{
     *   phone_e164: string,       // normalized storage format, e.g. "+2348012345678"
     *   country_iso2: string,     // uppercased, e.g. "NG"
     *   country_code: string,     // dial code with leading +, e.g. "+234"
     *   country_name: string,     // e.g. "Nigeria"
     * }
     */
    public function validateAndNormalize(string $rawPhone, string $countryIso2): array
    {
        $iso2 = strtoupper(trim($countryIso2));

        if (!Countries::exists($iso2)) {
            throw ValidationException::withMessages([
                'country_iso2' => ['Please select a valid country.'],
            ]);
        }

        try {
            $phoneNumber = new PhoneNumber($rawPhone, $iso2);

            if (!$phoneNumber->isValid()) {
                throw ValidationException::withMessages([
                    'phone' => ['Please enter a valid phone number for the selected country.'],
                ]);
            }

            $e164 = $phoneNumber->formatE164();
        } catch (NumberParseException $e) {
            throw ValidationException::withMessages([
                'phone' => ['Please enter a valid phone number for the selected country.'],
            ]);
        }

        $dialCode = '+' . PhoneNumberUtil::getInstance()->getCountryCodeForRegion($iso2);

        return [
            'phone_e164'   => $e164,
            'country_iso2' => $iso2,
            'country_code' => $dialCode,
            'country_name' => Countries::getName($iso2),
        ];
    }

    /**
     * True if the normalized phone number is already used by another
     * account. Excludes $ignoreUserId (e.g. the user's own current row on
     * a profile update, or a resumed-but-incomplete registration).
     */
    public function isDuplicate(string $phoneE164, ?int $ignoreUserId = null): bool
    {
        return \App\Models\User::where('phone', $phoneE164)
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();
    }
}
