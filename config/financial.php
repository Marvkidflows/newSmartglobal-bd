<?php
// Financial Team decisions of 27 Oct 2026, behind feature flags.
//
// Every flag defaults to FALSE so that deploying this code changes NO existing financial behaviour. Staging turns them on to validate;
// PRODUCTION must keep them off until the Financial Team and SSI management have formally approved the rules, staging tests have passed
// and the release has been authorised (Financial Team letter §6–§7). Nothing here is a rank decision.
return [
    // §1: approving a deposit records cleared wallet cash ONLY. It never creates an active investment. An investment is created later by a
    // separate, validated allocation from wallet cash.
    'separate_deposit_approval' => (bool) env('FINANCIAL_SEPARATE_DEPOSIT_APPROVAL', false),

    // §1B/§2: the wallet ledger (separate classifications), linked investment allocations and position-level principal movements.
    'ledger_enabled' => (bool) env('FINANCIAL_LEDGER_ENABLED', false),

    // §4E: cryptocurrency valuation stays DISABLED for rank eligibility until separately approved. Not a switch to flip casually.
    'crypto_rank_valuation_enabled' => (bool) env('FINANCIAL_CRYPTO_RANK_VALUATION', false),

    // §4A/§4C: no live FX provider is approved. Until the register has an approved source with a maximum rate age, no provider is called.
    'live_fx_enabled' => (bool) env('FINANCIAL_LIVE_FX_ENABLED', false),

    // §2B: the account-level "oldest investment first" fallback is NOT approved and is NOT implemented. Redemptions must name a position.
    'account_level_redemption_fallback' => false,
];
