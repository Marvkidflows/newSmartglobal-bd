<?php
// LOCATION: app/Support/CorsOrigins.php   (NEW FILE — D-10)
//
// Builds the CORS allow-list from the environment. Production gets ONLY the production site origins (plus any explicitly listed in CORS_ALLOWED_ORIGINS,
// e.g. a staging Pages domain). The localhost development origins are added only when APP_ENV is local / development / testing. A wildcard "*" is
// ignored (the API sends credentials, and a wildcard origin with credentials is unsafe), as is anything that is not a plain http(s) origin.

namespace App\Support;

class CorsOrigins
{
    public const PRODUCTION = ['https://smartsysteminvestmentglobal.com', 'https://www.smartsysteminvestmentglobal.com'];
    public const DEVELOPMENT = ['http://localhost:5173', 'http://127.0.0.1:5173'];
    public const DEV_ENVIRONMENTS = ['local', 'development', 'testing'];

    /** @return string[] */
    public static function resolve(?string $appEnv, ?string $extra): array
    {
        $origins = self::PRODUCTION;
        if (in_array(strtolower((string) $appEnv), self::DEV_ENVIRONMENTS, true)) {
            $origins = array_merge($origins, self::DEVELOPMENT);
        }
        foreach (explode(',', (string) $extra) as $candidate) {
            $candidate = rtrim(trim($candidate), '/');
            if ($candidate !== '' && preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $candidate)) {
                $origins[] = $candidate;
            }
        }
        return array_values(array_unique($origins));
    }
}
