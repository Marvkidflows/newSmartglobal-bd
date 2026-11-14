<?php
// LOCATION: config/registration.php
//
// Task 2 — Registration Team settings. Every value that touches applicant privacy or contact is conservative by default.

return [
    // Anti-enumeration budget for POST /api/register/stage1 (per IP address, per hour): how many responses that DISCLOSE account state
    // (email already registered / unfinished registration / phone already registered) one address may receive before a generic 429.
    // Legitimate people almost never hit this; bulk "is this email/phone registered?" probing does. This is a mitigation, not a full fix
    // (see docs/integration/SECURITY_AND_PERMISSIONS_REVIEW.md).
    'disclosure_limit_per_hour' => (int) env('REGISTRATION_DISCLOSURE_LIMIT_PER_HOUR', 20),
    // Stage 1 now answers every request identically. These cap ALL requests per IP address (not only "interesting" ones), so they reveal nothing:
    'stage1_attempts_per_hour'  => (int) env('REGISTRATION_STAGE1_ATTEMPTS_PER_HOUR', 30),
    'verify_attempts_per_hour'  => (int) env('REGISTRATION_VERIFY_ATTEMPTS_PER_HOUR', 60),
    // Floor (milliseconds) on the response time of stage 1 and verify, so timing does not depend on which case occurred (0 disables, used by tests).
    'min_response_ms'           => (int) env('REGISTRATION_MIN_RESPONSE_MS', 400),

    // PENDING MANAGEMENT / LEGAL APPROVAL. Recording that an (anonymous) visitor opened the registration page is a privacy-sensitive
    // act. It is OFF until SSI approves the privacy disclosure; even when ON, nothing is stored unless the visitor ticks the consent
    // notice on the registration page. Registration ATTEMPTS and applications (people who submit the form) are tracked regardless —
    // that is the applicant's own submitted data, not passive tracking.
    'visit_tracking_enabled' => (bool) env('REGISTRATION_VISIT_TRACKING', false),

    // Version of the tracking notice the consent checkbox refers to. Bump when the notice wording changes.
    'tracking_notice_version' => env('REGISTRATION_TRACKING_NOTICE_VERSION', '2026-11-06-draft'),

    // Internal team alert when a consenting visitor opens the page. Capped per hour so bots cannot flood the team.
    'notify_on_visit'          => (bool) env('REGISTRATION_NOTIFY_ON_VISIT', true),
    'visit_alert_max_per_hour' => (int) env('REGISTRATION_VISIT_ALERT_MAX_PER_HOUR', 30),

    // Hard ceiling on NEW anonymous visit records per hour across all visitors (protects the database from a distributed flood).
    'visit_max_new_per_hour' => (int) env('REGISTRATION_VISIT_MAX_NEW_PER_HOUR', 2000),

    // ── FOLLOW-UP POLICY — PENDING MANAGEMENT APPROVAL ───────────────────────────────────────────────────────────────────────────────
    // The three numbers below are the developers' PROPOSED DEFAULTS. They are NOT approved SSI policy. Each is read from the environment, so
    // Management can change any of them without a code change. `php artisan registration:settings` prints the values currently in force and
    // marks which are awaiting approval.
    //
    // An application still 'pending' with no activity for this long is "incomplete — needs follow-up".          (proposed: 24 hours)
    'incomplete_after_hours' => (int) env('REGISTRATION_INCOMPLETE_AFTER_HOURS', 24),

    // Applicant follow-up limits (duplicate-send prevention). Hard caps enforced server-side.
    'followup_cooldown_hours' => (int) env('REGISTRATION_FOLLOWUP_COOLDOWN_HOURS', 72),                         // (proposed: 72 hours)
    'followup_max'            => (int) env('REGISTRATION_FOLLOWUP_MAX', 3),                                      // (proposed: 3 messages)

    // Anonymous visit rows (never linked to an applicant) are deleted after this many days by `registration:maintain`.
    'visit_retention_days' => (int) env('REGISTRATION_VISIT_RETENTION_DAYS', 90),

    // ── RESUMING AN UNFINISHED REGISTRATION (security control, not a policy choice) ───────────────────────────────────────────────────
    // Resuming requires a one-time code emailed to the address on file. These are security parameters; tighten, do not loosen, unless
    // there is a documented reason.
    'resume_code_ttl_minutes'     => (int) env('REGISTRATION_RESUME_CODE_TTL_MINUTES', 10),
    'resume_code_max_attempts'    => (int) env('REGISTRATION_RESUME_CODE_MAX_ATTEMPTS', 5),    // wrong guesses before the code is destroyed
    'resume_request_cooldown_sec' => (int) env('REGISTRATION_RESUME_REQUEST_COOLDOWN_SEC', 60), // minimum gap between codes for one account
    'resume_requests_per_hour'    => (int) env('REGISTRATION_RESUME_REQUESTS_PER_HOUR', 5),    // caps both inbox-flooding and guessing
    'resume_mailer'               => env('REGISTRATION_RESUME_MAILER', 'brevo'),               // same transport as the existing OTP email
];
