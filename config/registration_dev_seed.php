<?php
// LOCATION: config/registration_dev_seed.php   (NEW FILE)
//
// Settings for the LOCAL / DEVELOPMENT-ONLY Registration Team test-account seeder (database/seeders/RegistrationTeamDevSeeder.php).
//
// Nothing in this file is a credential. The password is read from YOUR local .env (never committed) and the seeder refuses to run
// unless the application environment is one of the allow-listed development environments below. There is deliberately NO default password.
//
// Add to your local .env only (see docs/REGISTRATION_TEAM_DEV_SEEDER.md):
//   DEV_REGISTRATION_TEST_PASSWORD=<a password you choose, at least 12 characters>
//   DEV_REGISTRATION_TEST_EMAIL=registration.dev@ssi-dev.test      (optional; must end in a reserved test domain, see below)
//   DEV_REGISTRATION_TEST_NAME="Registration Test Member"           (optional)
//   DEV_REGISTRATION_TEST_ROLE=registration_member                  (optional; registration_member | registration_lead)

return [
    // The seeder runs ONLY when APP_ENV is one of these. 'production' and 'staging' (and any unknown/unset value, which Laravel treats
    // as production) are refused. Do not add them.
    'allowed_environments' => ['local', 'development', 'testing'],

    // The ONLY roles this seeder can ever assign. admin, financial, marvflow_* and investor are impossible by construction.
    'allowed_roles' => ['registration_member', 'registration_lead'],

    // The test e-mail address must belong to a reserved, non-routable domain so it can never be a real person's mailbox
    // (RFC 2606 / RFC 6761). Anything else is refused.
    'allowed_email_suffixes' => ['.test', '.local', '.localhost', '.invalid', '@example.com', '@example.org', '@example.net'],

    'email'    => env('DEV_REGISTRATION_TEST_EMAIL', 'registration.dev@ssi-dev.test'),
    'name'     => env('DEV_REGISTRATION_TEST_NAME', 'Registration Test Member'),
    'role'     => env('DEV_REGISTRATION_TEST_ROLE', 'registration_member'),
    'password' => env('DEV_REGISTRATION_TEST_PASSWORD'),   // no default, on purpose
    'min_password_length' => 12,
];
