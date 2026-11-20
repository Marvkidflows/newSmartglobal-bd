<?php
// LOCATION: config/sanctum.php   (NEW FILE — D-02)
//
// Only the keys set here override Sanctum's package defaults (the package merges its own config underneath this one, so 'guard', 'stateful' and
// 'middleware' keep their defaults). Sanctum had NO expiration configured, so tokens never expired.
//
// 'expiration' is the hard ceiling in minutes, measured from the token's created_at. It applies to every token, including old ones with a NULL
// expires_at. Per-role lifetimes are set when the token is issued (App\Support\AuthTokens) and can only be shorter than this ceiling.
// Values come from config/ssi_auth.php; see the note there about the provisional defaults (decision D1).

$ceiling = (int) env('AUTH_TOKEN_CEILING_MINUTES', 10080);

return [
    'expiration' => $ceiling > 0 ? $ceiling : 10080,
];
