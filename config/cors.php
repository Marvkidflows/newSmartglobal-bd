<?php
// D-10: origins come from the environment (App\Support\CorsOrigins). Production = the production site only, plus anything listed in CORS_ALLOWED_ORIGINS
// (comma-separated, e.g. a staging domain). localhost origins exist only in local / development / testing. "paths" no longer includes '*'.
return [
    'paths'                    => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => \App\Support\CorsOrigins::resolve(env('APP_ENV', 'production'), env('CORS_ALLOWED_ORIGINS')),
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => ['X-Export-Truncated'],
    'max_age'                  => 0,
    'supports_credentials'     => true,
];
