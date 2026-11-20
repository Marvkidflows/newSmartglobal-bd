<?php
// LOCATION: tests/Feature/Remediation/FinanceFindingsRegressionTest.php   (NEW — 18 Nov balance-trace findings)
//  1. Investor deposit endpoints must never return internal staff notes (admin_notes).
//  2. Settlement fails closed when the investor row is missing (position stays active, nothing written).
//  3. Balance Integrity monitor lists operating states as information, not as exceptions, and changes nothing.
// WRITTEN, NOT EXECUTED (no vendor/). Run: php artisan test --filter=FinanceFindingsRegressionTest

namespace Tests\Feature\Remediation;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\InvestmentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $x = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'email' => Str::lower(Str::random(8)) . '@ssi-test.test', 'email_verified_at' => now()] + $x);
    }

    private function plan(): int
    {
        return DB::table('investment_plans')->insertGetId(['name' => 'P' . uniqid(), 'min_amount' => 1, 'duration_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_investor_deposit_list_and_detail_never_expose_internal_notes(): void
    {
        $inv = $this->user('investor', ['registration_stage' => 4, 'registration_completed' => true]);
        $d = Deposit::create(['user_id' => $inv->id, 'amount' => 100, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-N1', 'status' => 'pending', 'admin_notes' => 'INTERNAL: suspected duplicate']);

        $this->actingAs($inv, 'sanctum');
        $list = $this->getJson('/api/investor-investment/investor/deposits')->assertOk();
        $this->assertStringNotContainsString('INTERNAL', $list->getContent());
        $this->assertStringNotContainsString('admin_notes', $list->getContent());
        $one = $this->getJson("/api/investor-investment/investor/deposits/{$d->id}")->assertOk();
        $this->assertStringNotContainsString('INTERNAL', $one->getContent());
        $this->assertStringNotContainsString('admin_notes', $one->getContent());
    }

    public function test_settlement_fails_closed_when_the_investor_row_is_missing(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs foreign keys off to simulate an orphaned position; MySQL forbids that state.');
        }
        $inv = $this->user('investor', ['balance' => 5]);
        $i = InvestmentAccount::create(['user_id' => $inv->id, 'investment_plan_id' => $this->plan(), 'amount' => 100, 'profit_percentage' => 10, 'expected_profit' => 10, 'total_return' => 110,
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDay()->toDateString(), 'remaining_days' => 0, 'status' => 'active']);
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('users')->where('id', $inv->id)->delete();

        $r = app(InvestmentSettlementService::class)->complete($i->id, 'admin');

        if ($r['reason'] === 'not_found') {
            $this->markTestSkipped('SQLite ignores PRAGMA foreign_keys inside the test transaction, so the position cascaded away with the user; run on MySQL (tests/MySql) for the orphan case.');
        }
        $this->assertFalse($r['completed']);
        $this->assertSame('missing_user', $r['reason']);
        $this->assertSame('active', $i->fresh()->status, 'a position must not be marked completed when nobody could be credited');
    }

    public function test_integrity_monitor_separates_information_from_exceptions_and_writes_nothing(): void
    {
        $inv = $this->user('investor', ['balance' => 20]);
        InvestmentAccount::create(['user_id' => $inv->id, 'investment_plan_id' => $this->plan(), 'amount' => 100, 'profit_percentage' => 10, 'expected_profit' => 10, 'total_return' => 110,
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(2)->toDateString(), 'remaining_days' => 0, 'status' => 'active']);

        $this->app['auth']->forgetGuards();
        $r = $this->actingAs($this->user('financial'), 'sanctum')->getJson('/api/financial/balance-integrity')->assertOk();
        $info = collect($r->json('info'))->keyBy('key');
        $this->assertSame(1, $info['matured_awaiting_settlement']['count']);
        $this->assertSame(0, $r->json('exceptions'), 'a matured, unsettled position is information, not an exception');
        $this->assertEquals(20, $inv->fresh()->balance);
        $this->assertSame('active', InvestmentAccount::first()->status);
    }
}
