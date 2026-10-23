<?php
// LOCATION: config/privacy.php
//
// Data Privacy Policy settings used by registration.
//
// DRAFT STATUS: the policy text lives in the frontend
// (src/pages/public/PrivacyPolicyPage.jsx). This file holds the two values
// the SERVER must be authoritative about: which policy version a user is
// acknowledging, and whether acknowledgement is mandatory.
//
// Whenever the policy text materially changes, bump `version` here AND the
// "Version" shown on the policy page together (the page reads the version
// from GET /api/privacy-policy, so it follows this value automatically).

return [

    // Identifier recorded against a user when they acknowledge the policy.
    'version' => env('PRIVACY_POLICY_VERSION', '2026-10-23-draft'),

    // Informational only; shown on the policy page. Leave null until SSI
    // Management / legal review approves the text — the page then labels
    // the policy as a draft.
    'effective_date' => env('PRIVACY_POLICY_EFFECTIVE_DATE'),

    // True once SSI Management/legal have approved the wording.
    'approved' => (bool) env('PRIVACY_POLICY_APPROVED', false),

    // PENDING MANAGEMENT DECISION: must registration be refused without
    // acknowledgement? Management has not specified this, so the safe default
    // is false (acknowledgement is recorded when given, but not enforced).
    // Set PRIVACY_ACKNOWLEDGEMENT_REQUIRED=true to enforce it on the server.
    'acknowledgement_required' => (bool) env('PRIVACY_ACKNOWLEDGEMENT_REQUIRED', false),
];
