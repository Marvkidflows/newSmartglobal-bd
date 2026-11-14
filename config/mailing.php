<?php
// TASK 3 — Mailing module configuration. NEW FILE. No secrets live here: every value is read from the environment.
return [
    // Laravel mailer used for ALL mailing-module sends. Defaults to the existing SSI 'brevo' mailer (see AppServiceProvider::boot()).
    'mailer' => env('MAILING_MAILER', 'brevo'),

    // Fallback sender when a mailbox has not been verified at the provider (the mailbox address is then used as Reply-To only).
    'default_from_address' => env('MAILING_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
    'default_from_name'    => env('MAILING_FROM_NAME', env('MAIL_FROM_NAME', 'Smart System Investment')),

    // Shared secret for provider webhooks (delivery events / inbound parsing). Requests without it are refused. Empty = webhooks disabled.
    'webhook_secret' => env('MAILING_WEBHOOK_SECRET'),

    // Version string stored with each consent record (bump when the consent wording / privacy notice changes).
    'consent_policy_version' => env('MAILING_CONSENT_POLICY_VERSION', '2026-11'),

    'max_attempts'   => (int) env('MAILING_MAX_ATTEMPTS', 3),
    'max_body_bytes' => 200000,
    'chunk_size'     => 500,

    // Public contact / enquiry forms.
    'contact' => [
        'per_minute'     => (int) env('MAILING_CONTACT_PER_MINUTE', 5),
        'per_day'        => (int) env('MAILING_CONTACT_PER_DAY', 20),
        'dedupe_minutes' => 10,   // identical submission from the same address inside this window is accepted but not stored twice
        'ack_hours'      => 24,   // at most one automatic acknowledgement per address in this window
        'max_message'    => 5000,
    ],

    // Provider API used ONLY to read the verified-sender list (mailbox connection check). Never used to store credentials.
    'brevo_api_key' => env('BREVO_API_KEY'),
];
