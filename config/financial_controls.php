<?php
// Financial integrity controls (audit 2 Nov 2026: F-03, F-04, F-14). NONE of these touch rank logic; rank code never calls these services.
//
// DEFAULTS ARE THE SAFE VALUES AND ARE A BEHAVIOUR CHANGE for the admin tools (resets, bulk balance, mass profit edits now need a second person).
// The thresholds below are PLACEHOLDERS pending Management / Financial Team confirmation (see docs/MANAGEMENT_DECISIONS.md). Nothing is invented as approved.
return [
    // Master switch for the balance-related second approvals (reset / bulk / large single adjustment). Profit adjustments have their own switch.
    'dual_approval_enabled' => (bool) env('FIN_DUAL_APPROVAL_ENABLED', true),

    'require_approval' => [
        'balance_reset' => (bool) env('FIN_APPROVAL_BALANCE_RESET', true),
        'bulk_balance'  => (bool) env('FIN_APPROVAL_BULK_BALANCE', true),
        // Existing contractual returns must not change without a documented second authorisation (Board rule). Do not disable casually.
        'profit_adjust' => (bool) env('FIN_APPROVAL_PROFIT_ADJUST', true),
        // R7: ANY change to an active investment's amount or profit rate needs a second approver. No policy defines a "small" correction,
        // so every correction is protected until Management decides otherwise (docs/REMAINING_MANAGEMENT_DECISIONS.md #9).
        'investment_correction' => (bool) env('FIN_APPROVAL_INVESTMENT_CORRECTION', true),
    ],

    // A single add/deduct of at least this many USD needs a second approver. Empty string disables the threshold. PLACEHOLDER VALUE.
    'balance_threshold_usd' => env('FIN_APPROVAL_THRESHOLD_USD', '10000.00'),

    // Who may approve each operation. The approver must ALWAYS be a different person from the requester (hard rule, not configurable).
    // Profit changes are Admin-initiated; whether the Financial Team may approve them is an OPEN Management decision (#3), so the safe default is
    // Admin-only (a second Admin). Set FIN_PROFIT_APPROVER_ROLES=admin,financial to allow cross-department approval once Management approves.
    'approver_roles' => [
        'balance_adjust' => ['admin', 'financial'],
        'bulk_balance'   => ['admin', 'financial'],
        'profit_adjust'  => array_values(array_filter(array_map('trim', explode(',', (string) env('FIN_PROFIT_APPROVER_ROLES', 'admin'))))),
        'profit_reverse' => array_values(array_filter(array_map('trim', explode(',', (string) env('FIN_PROFIT_APPROVER_ROLES', 'admin'))))),
        'investment_correction' => ['admin', 'financial'],
    ],

    // F-14 / R8 threshold-splitting control. Rolling window and cumulative limit per requester. EMPTY limit ⇒ the single-adjustment threshold above is
    // reused as the cumulative limit (conservative; no new number invented). Management decision #1.
    'balance_cumulative_window_hours'  => (int) env('FIN_CUMULATIVE_WINDOW_HOURS', 24),
    'balance_cumulative_threshold_usd' => env('FIN_CUMULATIVE_THRESHOLD_USD', ''),

    // Pending requests expire after this many hours. EMPTY = never expire (disabled pending Management decision #8). Run `php artisan ssi:approvals-maintain`.
    'approval_expiry_hours' => env('FIN_APPROVAL_EXPIRY_HOURS', ''),
    // Requests left 'executing' / legacy 'approved' longer than this are reconciled by `ssi:approvals-maintain` (never guesses about money).
    'stuck_execution_minutes' => (int) env('FIN_STUCK_EXECUTION_MINUTES', 15),

    // Optional ceiling on a corrected profit percentage. EMPTY = only the pre-existing technical bound (0–1000) applies; no cap is invented. Decision #2/#9.
    'profit_percentage_cap' => env('FIN_PROFIT_PERCENTAGE_CAP', ''),

    // Hard cap on one bulk operation's affected accounts unless explicitly raised (guards against an accidental "all investors" run).
    'bulk_max_accounts' => (int) env('FIN_BULK_MAX_ACCOUNTS', 500),
];
