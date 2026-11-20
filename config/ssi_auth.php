<?php
// LOCATION: config/ssi_auth.php   (NEW FILE — D-02)
//
// API token (Laravel Sanctum) lifetimes. PROVISIONAL DEFAULTS — Management decision D1 (token lifetime) is still open; these values exist so tokens are
// no longer immortal, they are not an approved policy. Change them with environment variables; no code change or redeploy of code is needed.
// A value of 0, a negative number or a non-number is NOT "no expiry": it falls back to the default below.
//
//   AUTH_STAFF_TOKEN_MINUTES      staff (admin, financial, MarvFlow, registration)   default 480   (8 hours)
//   AUTH_INVESTOR_TOKEN_MINUTES   investors and the registration wizard              default 10080 (7 days)
//   AUTH_TOKEN_CEILING_MINUTES    hard ceiling applied by Sanctum to EVERY token, including tokens issued before this change (which have no
//                                 expires_at of their own) and any lifetime above that is capped to it. Default 10080.
//
// Release effect: every existing session older than the ceiling, and every staff session older than the staff lifetime, ends at its next request
// and the person simply logs in again with the same credentials. No account, password or permission is changed.

return [
    'staff_token_lifetime_minutes'    => (int) env('AUTH_STAFF_TOKEN_MINUTES', 480),
    'investor_token_lifetime_minutes' => (int) env('AUTH_INVESTOR_TOKEN_MINUTES', 10080),
    'token_ceiling_minutes'           => (int) env('AUTH_TOKEN_CEILING_MINUTES', 10080),

    // D-12 / decision D24 (password policy) is OPEN. Defaults keep today's rule (8+ characters); these switches let Management tighten it without code changes.
    'password_min_length'     => (int) env('AUTH_PASSWORD_MIN_LENGTH', 8),          // never applied below 8
    'password_max_length'     => (int) env('AUTH_PASSWORD_MAX_LENGTH', 128),
    'password_require_mixed'  => (bool) env('AUTH_PASSWORD_REQUIRE_MIXED', false),  // upper + lower case and a digit
    'password_check_breached' => (bool) env('AUTH_PASSWORD_CHECK_BREACHED', false), // known-breach check (HaveIBeenPwned range API)
];
