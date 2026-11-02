<?php
// Diamond / Elite lending programme — HARD LOCK.
//
// The Board has NOT authorised any lending programme for production (Board decision §6). While `enabled` is false (the default, and
// the only acceptable value in production) EVERY lending path is refused by the application itself: applying, withdrawing,
// reviewing, deciding and configuring/activating the programme, and loan content is removed from investor-facing rank pages.
// A database flag (authorised/active) can never override this lock. Changing it requires the Board's separate written authorisation
// AND an approved lending policy; it is an environment decision, not an admin-panel toggle.
return [
    'enabled' => (bool) env('LENDING_PROGRAMME_ENABLED', false),
];
