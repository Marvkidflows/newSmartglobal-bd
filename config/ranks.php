<?php

return [
    // Investor account identifier. This is the SAME format the Financial dashboard
    // already shows (INVSTR-00012), derived from users.id — not a second identifier.
    'account_prefix' => env('RANKS_ACCOUNT_PREFIX', 'INVSTR-'),
    'account_pad'    => (int) env('RANKS_ACCOUNT_PAD', 5),

    // MANAGEMENT DECISION: may an Admin manually assign a rank the investor does
    // not currently qualify for (an "authorized override")? Off by default so
    // the configured rules are never bypassed unless Management says so.
    'allow_manual_override' => (bool) env('RANKS_ALLOW_MANUAL_OVERRIDE', false),

    // RANKING CURRENCY. Management's thresholds are expressed in USD, so USD is the ranking currency.
    // This is not a setting: it is what the thresholds mean. Records are valued in USD only after a person
    // verifies them (currency + recorded exchange rate for non-USD) — see usd_valuations / UsdValuationService.
    'ranking_currency' => 'USD',

    // Extra codes accepted as a "currency" of a stored amount, on top of the ISO-4217 list (intl). code => name.
    // A non-USD code ALWAYS needs a recorded rate, source and date — listing a code here never implies a rate.
    'extra_currencies' => ['USDT' => 'Tether (USDT)', 'USDC' => 'USD Coin (USDC)', 'BTC' => 'Bitcoin', 'ETH' => 'Ethereum'],

    // RATE SANITY CHECKS (warnings that the person must explicitly acknowledge; never silent, never automatic).
    //  • deviation: the typed rate differs from the most recent rate recorded for the SAME currency by more than
    //    this percent (catches inverted rates, e.g. 1500 instead of 0.00067, and misplaced decimals).
    //  • date tolerance: the rate date is more than this many days from the date the record was created.
    'rate_deviation_percent'  => (float) env('RANKS_RATE_DEVIATION_PERCENT', 20),
    'rate_date_tolerance_days' => (int) env('RANKS_RATE_DATE_TOLERANCE_DAYS', 7),

    // A staff-approved USD figure for a deposit may differ from (amount paid × recorded rate) by at most this percent
    // (bank fees, rounding) without an explicit acknowledgement. SSI has no other approved conversion rule: the USD
    // value is the paid amount × the recorded rate, rounded half-up to the cent.
    'approved_amount_tolerance_percent' => (float) env('RANKS_APPROVED_AMOUNT_TOLERANCE_PERCENT', 1),

    // A record created within this many seconds of an earlier, otherwise-identical one (same investor, plan,
    // amount, start date) is treated as a duplicate (double-submit) and excluded until Financial confirms it.
    'duplicate_window_seconds' => (int) env('RANKS_DUPLICATE_WINDOW_SECONDS', 60),

    // ACTIVATION GATE for money criteria. Amounts are stored with no currency, so each record is valued in USD
    // by a person (UsdValuationService) and only verified values are used. Until Management confirms in writing
    // that the currency handling has been verified end-to-end (and the review queue has been worked), criteria
    // that use a money amount can be SAVED AS DRAFTS but cannot be ACTIVATED, and the engine ignores any such
    // criterion. Set to true ONLY after that confirmation.
    'currency_confirmed' => (bool) env('RANKS_CURRENCY_CONFIRMED', false),

    // PREREQUISITES applied to EVERY rank grant (automatic and manual) in addition to the
    // Admin-configured criteria. Not criteria: they are compliance gates.
    // Management has asked for an active account and verification; the only verification the
    // platform has is Admin-approved KYC. Confirm whether other compliance rules apply.
    'require_active_account' => (bool) env('RANKS_REQUIRE_ACTIVE_ACCOUNT', true),
    'require_verified_kyc'   => (bool) env('RANKS_REQUIRE_VERIFIED_KYC', true),

    // Investors processed per queued chunk during a re-evaluation run.
    'chunk_size' => (int) env('RANKS_CHUNK_SIZE', 200),

    // Max investors examined by the dry-run preview.
    'preview_limit' => (int) env('RANKS_PREVIEW_LIMIT', 2000),

    // Pending deliveries older than this are treated as stuck and retried.
    'notification_stale_minutes' => (int) env('RANKS_NOTIFICATION_STALE_MINUTES', 10),
];
