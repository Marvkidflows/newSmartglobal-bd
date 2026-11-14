<?php
// LOCATION: tests/Feature/Rank/RankRouteSecuritySweepTest.php
//
// Phase 3: a standing guard over EVERY rank, benefit, incentive, loan and financial-control route. It reads the live route table, so a route
// added later without authentication, the staff-status guard, a role middleware or (for writes) a rate limit fails here instead of shipping.
// It complements RankAuthorizationTest, which proves the in-controller gates by calling the routes as each role.
//
// STATUS: NOT EXECUTED by its author. Run: php -d memory_limit=-1 vendor\bin\pest --filter=RankRouteSecuritySweepTest

namespace Tests\Feature\Rank;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

class RankRouteSecuritySweepTest extends RankTestCase
{
    private const ROLES = ['admin', 'financial', 'marvflow', 'investor'];

    /** @return LaravelRoute[] */
    private function sensitiveRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $hit = preg_match('#^api/(admin/(ranks|loans)|financial/(rank|controls|approvals|positions)|investor-investment/(loans|rank))#', $uri)
                || str_contains($uri, 'incentive') || str_contains($uri, 'loan');
            if ($hit) $out[] = $route;
        }
        return $out;
    }

    private function mw(LaravelRoute $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
    }

    private function label(LaravelRoute $route): string
    {
        return implode('|', array_diff($route->methods(), ['HEAD'])) . ' /' . $route->uri();
    }

    public function test_the_sweep_actually_found_the_sensitive_routes(): void
    {
        $routes = $this->sensitiveRoutes();
        $this->assertGreaterThan(50, count($routes), 'the route patterns no longer match the rank module: update this test');

        $writes = array_filter($routes, fn ($r) => (bool) array_diff($r->methods(), ['GET', 'HEAD']));
        $this->assertGreaterThan(40, count($writes));
    }

    public function test_no_sensitive_route_is_public_and_each_has_the_staff_guard_and_a_role(): void
    {
        $bad = [];
        foreach ($this->sensitiveRoutes() as $route) {
            $mw = $this->mw($route);
            if (!in_array('auth:sanctum', $mw, true))            $bad[] = $this->label($route) . '  [no auth:sanctum]';
            if (!in_array('staff.active', $mw, true))            $bad[] = $this->label($route) . '  [no staff.active guard]';
            if (!array_intersect(self::ROLES, $mw))              $bad[] = $this->label($route) . '  [no role middleware]';
        }
        $this->assertSame([], $bad, "Unprotected sensitive routes:\n" . implode("\n", $bad));
    }

    public function test_every_state_changing_sensitive_route_is_rate_limited(): void
    {
        $bad = [];
        foreach ($this->sensitiveRoutes() as $route) {
            if (!array_diff($route->methods(), ['GET', 'HEAD'])) continue;
            $throttled = (bool) array_filter($this->mw($route), fn ($m) => str_starts_with($m, 'throttle'));
            if (!$throttled) $bad[] = $this->label($route);
        }
        $this->assertSame([], $bad, "State-changing routes without a rate limit:\n" . implode("\n", $bad));
    }

    public function test_admin_only_rank_areas_are_not_reachable_with_a_financial_or_investor_role_middleware(): void
    {
        // The role middleware on the admin rank areas must be "admin" alone (financial staff reach ranks only through their own, narrower /financial routes).
        $bad = [];
        foreach ($this->sensitiveRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/admin/ranks') && !str_starts_with($route->uri(), 'api/admin/loans')) continue;
            $roles = array_values(array_intersect(self::ROLES, $this->mw($route)));
            if ($roles !== ['admin']) $bad[] = $this->label($route) . '  [roles: ' . implode(',', $roles) . ']';
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
