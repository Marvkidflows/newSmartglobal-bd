<?php
// LOCATION: database/seeders/Concerns/RefusesUnsafeSeeding.php   (NEW FILE — D-01)
//
// Shared guard for seeders that create demo / test accounts. Both checks run BEFORE any query is issued, so a refusal can never leave a partial write.
//   1. APP_ENV must be on the allow-list in config('seeding.demo_environments') (local / development / testing).
//   2. The configured database must be on this machine. A developer .env that copies the production database settings but keeps APP_ENV=local
//      must not be able to write demo accounts into the live database.

namespace Database\Seeders\Concerns;

use RuntimeException;

trait RefusesUnsafeSeeding
{
    protected function assertDemoSeedingAllowed(): void
    {
        $allowed = (array) config('seeding.demo_environments', []);
        if ($allowed === [] || !app()->environment($allowed)) {
            throw new RuntimeException('Refusing to run: demo accounts and demo data are for local development only (APP_ENV must be one of: ' . implode(', ', $allowed) . '). Current APP_ENV: ' . (app()->environment() ?: '(empty)') . '. Nothing was created, changed or deleted.');
        }

        $name = (string) config('database.default');
        $conn = (array) config("database.connections.$name");
        if (($conn['driver'] ?? '') === 'sqlite') {
            return;                                                             // file / in-memory database: always local
        }
        $host = (string) ($conn['host'] ?? '');
        if (!empty($conn['url'])) {
            $host = (string) (parse_url((string) $conn['url'], PHP_URL_HOST) ?: $host);
        }
        if (!self::isLocalDatabaseHost($host)) {
            throw new RuntimeException('Refusing to run: the configured database is not on this machine (it looks like a remote or hosted database), even though APP_ENV allows development. Demo data is never written to a shared or live database. Nothing was created, changed or deleted.');
        }
    }

    /** True for a host that is this machine (or a bare Docker/compose service name). A real domain or an IP address counts as remote. */
    public static function isLocalDatabaseHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host, " \t\n\r\0\x0B[]"));
        if ($host === '' || str_starts_with($host, '/')) return true;          // no host / unix socket
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'host.docker.internal'], true)) return true;
        foreach (['.test', '.local', '.localhost'] as $suffix) {
            if (str_ends_with($host, $suffix)) return true;
        }
        return !str_contains($host, '.') && !str_contains($host, ':');         // e.g. the compose service name "mysql"
    }
}
