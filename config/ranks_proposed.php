<?php
// LOCATION: config/ranks_proposed.php
//
// PROPOSED rank qualification criteria supplied by SSI Management.
// ⚠ NOT APPROVED. Subject to final Management approval. This file is DATA ONLY:
//   • nothing reads it at runtime, in any environment;
//   • the only consumer is `php artisan ranks:load-proposed`, which refuses to run outside
//     the environments listed below, so it can never load or activate these in production;
//   • in production the approved criteria are entered by an authorized Admin in
//     Investor Ranks → Rank Criteria (validated, versioned and audited like any other rule).
//
// Interpretation (to be confirmed by Management — see RANK_MODULE.md §9):
//   "$100–$999 investment; minimum 1 completed cycle" is loaded as
//       total invested amount ≥ min_amount   AND   completed investment cycles ≥ min_cycles
//   The upper figure of each band (band_max) is descriptive: a higher band is reached by meeting
//   the next rank's minimums, and the engine always grants the HIGHEST rank whose rule is met.
//   An Admin who wants a hard upper limit can switch the amount condition to "is between" in the
//   dashboard — no code change needed.

return [
    'allowed_environments' => ['local', 'testing', 'development'],

    // Second guard. APP_ENV=local is common on a developer machine whose .env still points at a
    // shared/remote database, so the environment NAME alone is not proof of a safe target. The
    // loader also refuses unless the default database connection is sqlite or one of these hosts.
    'allowed_db_hosts' => ['127.0.0.1', 'localhost', '::1', 'host.docker.internal', 'mysql', 'db'],

    'ranks' => [
        'bronze'   => ['min_amount' => 100,   'band_max' => 999,   'min_cycles' => 1],
        'silver'   => ['min_amount' => 1000,  'band_max' => 4999,  'min_cycles' => 2],
        'gold'     => ['min_amount' => 5000,  'band_max' => 9999,  'min_cycles' => 3],
        'platinum' => ['min_amount' => 10000, 'band_max' => 19999, 'min_cycles' => 5],
        'diamond'  => ['min_amount' => 20000, 'band_max' => 49999, 'min_cycles' => 8],
        'elite'    => ['min_amount' => 50000, 'band_max' => null,  'min_cycles' => 12],
    ],
];
