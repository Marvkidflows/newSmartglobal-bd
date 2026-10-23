<?php
// LOCATION: tests/Feature/Rank/RankAuthorizationTest.php
//
// Server-side authorization for EVERY Admin rank route, checked mechanically rather than by hand:
//  1. A route sweep: no non-admin role (and no guest) can reach any /api/admin/ranks/* route.
//  2. A gate map: each state-changing action is protected by the ability we intend. Denying that single
//     ability to an otherwise-valid Admin yields 403, while denying an UNRELATED ability does not.
//  3. The alert-retry endpoint specifically (it re-queues jobs, so read access is not enough).
//  4. The audit tables expose no HTTP route that could edit or delete a record.

namespace Tests\Feature\Rank;

use App\Models\InvestorRankChange;
use App\Models\RankChangeNotification;
use App\Models\RankDowngradeReview;
use App\Models\RankEvaluationRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

class RankAuthorizationTest extends RankTestCase
{
    /** @return array<int, array{0:string,1:string}> [method, uri-with-placeholders] for every rank route */
    private function rankRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/admin/ranks')) continue;
            foreach (array_diff($route->methods(), ['HEAD']) as $m) $out[] = [$m, $route->uri()];
        }
        return $out;
    }

    private function fill(string $uri, int $id = 999999): string
    {
        return '/' . preg_replace('/\{[^}]+\}/', (string) $id, $uri);
    }

    public function test_the_sweep_actually_found_the_rank_routes(): void
    {
        $routes = $this->rankRoutes();
        $this->assertGreaterThanOrEqual(24, count($routes));   // 24 controller actions (RANK_MODULE.md)
        $mutating = array_filter($routes, fn ($r) => $r[0] !== 'GET');
        $this->assertGreaterThanOrEqual(13, count($mutating));
    }

    public function test_no_guest_can_reach_any_rank_route(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // authorization, not rate limiting, is under test

        foreach ($this->rankRoutes() as [$method, $uri]) {
            $res = $this->json($method, $this->fill($uri), []);
            $this->assertSame(401, $res->getStatusCode(), "guest reached $method $uri");
        }
    }

    public function test_no_non_admin_role_can_reach_any_rank_route(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach (['financial', 'marvflow_lead', 'marvflow_member', 'investor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            foreach ($this->rankRoutes() as [$method, $uri]) {
                $res = $this->actingAs($user, 'sanctum')->json($method, $this->fill($uri), []);
                $this->assertContains($res->getStatusCode(), [403, 404], "$role got {$res->getStatusCode()} from $method $uri (must be refused)");
            }
        }
    }

    public function test_each_state_changing_action_is_protected_by_the_intended_gate(): void
    {
        $investor = $this->investor();
        $crit   = $this->createCriterion('bronze', [$this->cond('total_invested', '>=', 100)], ['is_active' => false])['criterion']['id'];
        $review = RankDowngradeReview::create([
            'investor_id' => $investor->id, 'investor_rank_id' => $this->rank('bronze')->id, 'rank_criterion_id' => $crit,
            'criterion_version' => 1, 'fingerprint' => sha1('x'), 'failed_conditions' => [], 'status' => 'open',
        ]);
        $run = RankEvaluationRun::create(['reference' => 'ER-AUTHTEST', 'trigger' => 'manual', 'status' => 'failed', 'total' => 0, 'heartbeat_at' => now()]);

        $body = ['rank_id' => $this->rank('gold')->id, 'reason' => 'Authorization test reason'];

        // [method, url, payload, gate that must guard it]
        $map = [
            ['POST',   "/api/admin/ranks/investors/{$investor->id}/assign",    $body,                                  'ranks.assign'],
            ['POST',   "/api/admin/ranks/investors/{$investor->id}/downgrade", $body,                                  'ranks.downgrade'],
            ['POST',   "/api/admin/ranks/reviews/{$review->id}/retain",        ['reason' => 'Authorization test'],     'ranks.downgrade'],
            ['POST',   '/api/admin/ranks/criteria',                            $this->payload('silver', [$this->cond('total_invested', '>=', 1)]), 'ranks.manage-criteria'],
            ['PUT',    "/api/admin/ranks/criteria/{$crit}",                    ['name' => 'X', 'logic' => 'all', 'conditions' => [$this->cond('total_invested', '>=', 5)]], 'ranks.manage-criteria'],
            ['POST',   "/api/admin/ranks/criteria/{$crit}/activate",           [],                                     'ranks.manage-criteria'],
            ['POST',   "/api/admin/ranks/criteria/{$crit}/deactivate",         [],                                     'ranks.manage-criteria'],
            ['DELETE', "/api/admin/ranks/criteria/{$crit}",                    ['reason' => 'Authorization test'],     'ranks.manage-criteria'],
            ['POST',   '/api/admin/ranks/criteria/validate',                   $this->payload('gold', [$this->cond('total_invested', '>=', 1)]), 'ranks.manage-criteria'],
            ['POST',   '/api/admin/ranks/criteria/preview',                    $this->payload('gold', [$this->cond('total_invested', '>=', 1)]), 'ranks.manage-criteria'],
            ['POST',   '/api/admin/ranks/runs',                                [],                                     'ranks.manage-criteria'],
            ['POST',   "/api/admin/ranks/runs/{$run->id}/resume",              [],                                     'ranks.manage-criteria'],
            ['POST',   '/api/admin/ranks/alerts/retry',                        [],                                     'ranks.manage-criteria'],
            ['POST',   '/api/admin/ranks/currency-policy/review',              ['note' => 'Authorization test review'], 'financial.review-currency-policy'],
            ['POST',   '/api/admin/ranks/currency-policy/confirm',             ['note' => 'Authorization test confirm', 'unverified_handling' => 'require_clear'], 'ranks.confirm-currency-policy'],
            ['POST',   '/api/admin/ranks/currency-policy/revoke',              ['reason' => 'Authorization test revoke'], 'ranks.confirm-currency-policy'],
            ['GET',    '/api/admin/ranks/audit',                               [],                                     'ranks.view-audit'],
            ['GET',    '/api/admin/ranks/criteria/history',                    [],                                     'ranks.view-audit'],
            ['GET',    '/api/admin/ranks',                                     [],                                     'ranks.view'],
            ['GET',    '/api/admin/ranks/alerts',                              [],                                     'ranks.view'],
        ];

        Queue::fake();   // keep job side effects out of an authorization test
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // the 5/min runs limiter shares a bucket per user

        foreach ($map as [$method, $url, $payload, $ability]) {
            // (a) Valid Admin, ability denied → 403 and no side effect.
            $writes = fn () => [InvestorRankChange::count(), \App\Models\RankCriteriaHistory::count(), RankEvaluationRun::count(), RankDowngradeReview::where('status', '!=', 'open')->count()];
            $before = $writes();
            Gate::before(fn ($u, $a) => $a === $ability ? false : null);
            $denied = $this->actingAs($this->admin, 'sanctum')->json($method, $url, $payload);
            $this->assertSame(403, $denied->getStatusCode(), "$method $url is not guarded by [$ability] (got {$denied->getStatusCode()})");
            $this->assertSame($before, $writes(), "a DENIED $method $url still wrote data");

            // (b) Denying a DIFFERENT, unrelated ability must not block it.
            $other = $ability === 'ranks.view-audit' ? 'ranks.assign' : 'ranks.view-audit';
            $this->flushGateCallbacks();
            Gate::before(fn ($u, $a) => $a === $other ? false : null);
            $ok = $this->actingAs($this->admin, 'sanctum')->json($method, $url, $payload);
            $this->assertNotSame(403, $ok->getStatusCode(), "$method $url wrongly depends on [$other]");
            $this->flushGateCallbacks();
        }
    }

    /** Gate::before callbacks accumulate; reset them by rebuilding the gate's callback list. */
    private function flushGateCallbacks(): void
    {
        $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);
        $ref = new \ReflectionProperty($gate, 'beforeCallbacks');
        $ref->setAccessible(true);
        $ref->setValue($gate, []);
    }

    protected function tearDown(): void
    {
        $this->flushGateCallbacks();
        parent::tearDown();
    }

    // ── the alert-retry endpoint (was gated only by read access) ──────────────

    private function failedAlert(): RankChangeNotification
    {
        $u = $this->investor();
        $change = InvestorRankChange::create([
            'reference' => 'RC-AUTH', 'investor_id' => $u->id, 'account_id' => $u->account_id, 'sequence' => 1,
            'previous_rank_id' => null, 'new_rank_id' => $this->rank('bronze')->id, 'change_type' => InvestorRankChange::AUTOMATIC_UPGRADE,
            'is_automatic' => true, 'actor_name' => 'System (Rank Engine)', 'reason' => 'seed', 'created_at' => now(),
        ]);
        return RankChangeNotification::create(['investor_rank_change_id' => $change->id, 'status' => 'failed']);
    }

    public function test_admin_can_retry_failed_alerts(): void
    {
        $this->failedAlert();
        Queue::fake();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/alerts/retry')
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Re-queued 1 upgrade alert'));

        Queue::assertPushed(\App\Jobs\SendRankChangeNotificationJob::class, 1);
    }

    public function test_read_only_access_is_not_enough_to_retry_alerts(): void
    {
        $this->failedAlert();
        Queue::fake();
        Gate::before(fn ($u, $a) => $a === 'ranks.manage-criteria' ? false : null);   // can view, cannot manage

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ranks/alerts')->assertOk();            // still reads
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/ranks/alerts/retry')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_forbidden_roles_cannot_retry_alerts_and_nothing_is_queued(): void
    {
        $this->failedAlert();
        Queue::fake();

        // Guest FIRST: actingAs() persists for the rest of the test, so a "guest" request made after it is not a guest.
        $this->postJson('/api/admin/ranks/alerts/retry')->assertUnauthorized();

        foreach (['financial', 'marvflow_lead', 'marvflow_member', 'investor'] as $role) {
            $u = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($u, 'sanctum')->postJson('/api/admin/ranks/alerts/retry')->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    // ── audit history has no edit/delete surface ──────────────────────────────

    public function test_no_http_route_can_edit_or_delete_audit_history(): void
    {
        foreach ($this->rankRoutes() as [$method, $uri]) {
            if (str_contains($uri, '/audit') || str_contains($uri, '/history')) {
                $this->assertSame('GET', $method, "audit/history route $uri allows $method");
            }
        }
        // The only DELETE under ranks is criteria archive (soft delete, history retained).
        $deletes = array_values(array_filter($this->rankRoutes(), fn ($r) => $r[0] === 'DELETE'));
        $this->assertSame([['DELETE', 'api/admin/ranks/criteria/{criterion}']], $deletes);
    }
}
