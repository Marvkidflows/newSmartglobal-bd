<?php
// LOCATION: config/seeding.php   (NEW FILE — D-01)
//
// Which application environments may create DEMO data (demo administrator / demo investor / sample tasks / sample investment plans).
// Anything else — production, staging, and any unset or unknown APP_ENV (Laravel treats unknown as production) — is refused for demo data.
// This is not a credential file and holds no secrets. Do not add 'production' or 'staging' here.

return [
    'demo_environments' => ['local', 'development', 'testing'],
];
