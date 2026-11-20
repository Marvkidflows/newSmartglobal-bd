<?php
// LOCATION: database/seeders/Concerns/RefusesUnsafeSeedingHost.php   (NEW FILE — D-07)
// Static wrapper so test scaffolding can reuse the D-01 host classification without needing a seeder instance.
namespace Database\Seeders\Concerns;

final class RefusesUnsafeSeedingHost
{
    private static function probe(): object
    {
        return new class { use RefusesUnsafeSeeding; };
    }

    public static function isLocal(?string $host): bool
    {
        $p = self::probe();
        return $p::isLocalDatabaseHost($host);
    }
}
