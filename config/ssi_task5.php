<?php
// Task 5 - Referral bonus management and withdrawal fee configuration.
//
// Everything here is a SAFE DEFAULT. Nothing activates a business rule that Management has not approved:
//  - the referral policy ships UNAPPROVED (rewards can be tracked as Pending but never approved/credited until an Administrator
//    records the approved policy reference);
//  - the withdrawal-fee policy ships as 'none' (zero fee) and INACTIVE.
return [
    'referral' => [
        'default_reward'   => '5.00',   // USD, per the 24 Oct 2026 task document (subject to the approved SSI referral policy)
        'default_currency' => 'USD',
        // Fraud heuristics (flag for Financial review only - they never move money or change a balance)
        'velocity_window_hours' => 24,
        'velocity_threshold'    => 5,     // this many registrations through one code inside the window => flag
        'fast_activation_minutes' => 10,  // referred account created within N minutes of the referrer's own account => flag (combined signal)
        'visit_throttle_per_minute' => 30,
    ],
    'withdrawal_fee' => [
        'default_currency' => 'USD',
        'policy_ids_are_versioned' => true,
        // Reversing / waiving a COLLECTED fee (optionally refunding it to the wallet) is DISABLED until Management approves the fee-refund rules
        // (see docs/integration/WITHDRAWAL_CANCELLATION_REVERSAL_PROPOSAL.md). 'review' flagging stays available. Enable only after written approval.
        'allow_adjustments' => (bool) env('WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED', false),
    ],
];
