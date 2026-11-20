<?php
// LOCATION: tests/Feature/DepositTelegramConnectionTest.php
//
// Stage 1: after an investor submits a deposit it is saved as PENDING, the investor's OWN Telegram contact is resolved
// server-side and returned (name + link only), the assigned agent is notified at most once and only when Telegram
// confirms, and nothing is paid / approved / credited / invested by the submission.

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\User;
use App\Services\DepositAgentResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Rank\RankTestCase;

class DepositTelegramConnectionTest extends RankTestCase
{
    protected InvestmentPlan $plan;
    protected const URL = '/api/investor-investment/investor/deposits/initiate';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = InvestmentPlan::create(['name' => 'Starter', 'min_amount' => 1, 'profit_percentage' => 10, 'profit_percent' => 10, 'duration_days' => 30, 'status' => 'active']);
        config(['financial.ledger_enabled' => true]);   // the wallet ledger is OFF by default (FINANCIAL_LEDGER_ENABLED); these tests assert its rows
        config(['services.telegram.bot_token' => '123456:TESTTOKEN', 'services.telegram.chat_id' => '-100777', 'services.telegram.agent_username' => null, 'services.telegram.agent_name' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
    }

    /** Replace (not add to) the Telegram HTTP stubs: Laravel uses the FIRST matching stub, so a second Http::fake() would be ignored. */
    protected function fakeTelegram($response): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telegram.org/*' => $response]);
    }

    protected function agent(string $handle = 'agent_alpha', ?string $chat = null, array $o = []): User
    {
        return User::factory()->create(array_merge(['role' => 'financial', 'status' => 'active', 'telegram_username' => $handle, 'telegram_chat_id' => $chat], $o));
    }

    protected function assigned(User $agent, array $o = []): User
    {
        $u = $this->investor($o);
        $u->forceFill(['assigned_agent_id' => $agent->id])->save();
        return $u;
    }

    protected function submit(User $u, array $o = [])
    {
        return $this->actingAs($u, 'sanctum')->postJson(self::URL, array_merge(['investment_plan_id' => $this->plan->id, 'amount' => 500], $o));
    }

    protected function agentSends(string $chatId): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), '/sendMessage') && (string) ($req['chat_id'] ?? '') === $chatId)->count();
    }

    // ── saved as pending; nothing moves ───────────────────────────────────────────

    public function test_submission_saves_a_pending_deposit_and_moves_no_money(): void
    {
        $u = $this->assigned($this->agent());
        $before = (float) $u->fresh()->balance;
        $res = $this->submit($u)->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('duplicate', false);

        $d = Deposit::findOrFail($res->json('deposit_id'));
        $this->assertSame('pending', $d->status);
        $this->assertSame($u->id, (int) $d->user_id);
        $this->assertNull($d->payment_verified_at);
        $this->assertNull($d->processed_at);
        $this->assertSame($before, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('deposit_id', $d->id)->where('classification', 'pending_deposit')->count());
    }

    // ── agent lookup ──────────────────────────────────────────────────────────────

    public function test_each_investor_is_connected_to_their_own_assigned_agent(): void
    {
        config(['services.telegram.agent_username' => 'default_rep']);
        $a = $this->assigned($this->agent('agent_alpha'));
        $b = $this->assigned($this->agent('agent_bravo'));

        $la = $this->submit($a)->assertCreated()->json('agent.link');
        $lb = $this->submit($b)->assertCreated()->json('agent.link');

        $this->assertStringStartsWith('https://t.me/agent_alpha?text=', $la);
        $this->assertStringStartsWith('https://t.me/agent_bravo?text=', $lb);
        $this->assertStringNotContainsString('default_rep', $la . $lb);
    }

    public function test_unassigned_investor_gets_the_existing_deployment_wide_representative(): void
    {
        config(['services.telegram.agent_username' => '@ssi_rep_demo', 'services.telegram.agent_name' => 'SSI Desk']);
        $res = $this->submit($this->investor())->assertCreated();
        $this->assertStringStartsWith('https://t.me/ssi_rep_demo?text=', $res->json('agent.link'));
        $this->assertSame('SSI Desk', $res->json('agent.name'));
    }

    public function test_nothing_is_invented_when_no_contact_exists_and_the_deposit_is_still_saved(): void
    {
        $res = $this->submit($this->investor())->assertCreated();
        $this->assertNull($res->json('agent'));
        $this->assertSame('pending', Deposit::findOrFail($res->json('deposit_id'))->status);
        $this->assertStringNotContainsString('AgentlanFrank', $res->getContent());
    }

    public function test_assigned_agent_without_a_valid_username_is_not_replaced_by_someone_elses_contact(): void
    {
        config(['services.telegram.agent_username' => 'default_rep']);
        foreach ([null, 'bad name', 'ab', 'https://evil.example/x'] as $bad) {
            $u = $this->assigned($this->agent('placeholder', null, ['telegram_username' => $bad]));
            $this->assertNull($this->submit($u)->assertCreated()->json('agent'), 'handle: ' . var_export($bad, true));
        }
    }

    public function test_an_invalid_assignment_is_ignored_server_side(): void
    {
        config(['services.telegram.agent_username' => 'default_rep']);
        $inactive = $this->agent('agent_gone', null, ['status' => 'suspended']);
        $notStaff = User::factory()->create(['role' => 'investor', 'status' => 'active', 'telegram_username' => 'sneaky_investor']);
        foreach ([$inactive, $notStaff] as $bad) {
            $u = $this->assigned($bad);
            $link = $this->submit($u)->assertCreated()->json('agent.link');
            $this->assertStringStartsWith('https://t.me/default_rep?text=', $link);
        }
    }

    public function test_the_client_cannot_choose_the_agent_or_the_owner(): void
    {
        $mine = $this->assigned($this->agent('agent_alpha'));
        $other = $this->assigned($this->agent('agent_bravo'));
        $res = $this->submit($mine, ['user_id' => $other->id, 'agent' => 'agent_bravo', 'assigned_agent_id' => $other->assigned_agent_id, 'status' => 'approved'])->assertCreated();
        $d = Deposit::findOrFail($res->json('deposit_id'));
        $this->assertSame($mine->id, (int) $d->user_id);
        $this->assertSame('pending', $d->status);
        $this->assertStringStartsWith('https://t.me/agent_alpha?', $res->json('agent.link'));
    }

    // ── destination generation / data exposure ────────────────────────────────────

    public function test_link_carries_name_and_reference_only_and_no_chat_id_is_exposed(): void
    {
        $u = $this->assigned($this->agent('agent_alpha', '555000111'), ['name' => 'Ada Obi', 'email' => 'ada@example.test']);
        $res = $this->submit($u, ['amount' => 12345])->assertCreated();
        $text = urldecode(explode('?text=', $res->json('agent.link'))[1]);

        $this->assertStringContainsString('Ada Obi', $text);
        $this->assertStringContainsString($res->json('reference'), $text);
        $this->assertStringNotContainsString('12345', $text);
        $this->assertSame(['name', 'link'], array_keys($res->json('agent')));
        $this->assertStringNotContainsString('555000111', $res->getContent());
        $this->assertStringNotContainsString('ada@example.test', $res->getContent());
    }

    // ── duplicate submissions ─────────────────────────────────────────────────────

    public function test_a_retry_with_the_same_key_returns_the_same_deposit_and_notifies_once(): void
    {
        $u = $this->assigned($this->agent('agent_alpha', '555000111'));
        $key = 'key-' . str_repeat('a', 20);

        $first  = $this->submit($u, ['idempotency_key' => $key])->assertCreated();
        $second = $this->submit($u, ['idempotency_key' => $key])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('deposit_id'), $second->json('deposit_id'));
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, Deposit::where('user_id', $u->id)->count());
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('user_id', $u->id)->where('classification', 'pending_deposit')->count());
        $this->assertSame(1, $this->agentSends('555000111'));
        $this->assertSame(1, Http::recorded(fn ($r) => (string) ($r['chat_id'] ?? '') === '-100777')->count());   // staff chat: once too
    }

    public function test_the_same_key_from_two_investors_does_not_collide_and_is_never_shared(): void
    {
        $key = 'shared-' . str_repeat('b', 20);
        $a = $this->investor(); $b = $this->investor();
        $ra = $this->submit($a, ['idempotency_key' => $key])->assertCreated();
        $rb = $this->submit($b, ['idempotency_key' => $key])->assertCreated();
        $this->assertNotSame($ra->json('deposit_id'), $rb->json('deposit_id'));
        $this->assertSame($b->id, (int) Deposit::find($rb->json('deposit_id'))->user_id);
    }

    public function test_the_database_refuses_a_second_deposit_with_the_same_key(): void
    {
        $u = $this->investor();
        $this->submit($u, ['idempotency_key' => 'dbkey-' . str_repeat('c', 20)])->assertCreated();
        $this->expectException(\Illuminate\Database\QueryException::class);
        Deposit::create(['user_id' => $u->id, 'investment_plan_id' => $this->plan->id, 'amount' => 1, 'payment_method' => 'pending',
            'transaction_reference' => 'DEP-X-1', 'status' => 'pending', 'idempotency_key' => 'dbkey-' . str_repeat('c', 20)]);
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        $this->submit($this->investor(), ['idempotency_key' => 'short'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, Deposit::count());
    }

    // ── notification success / failure ────────────────────────────────────────────

    public function test_agent_is_notified_with_minimal_data_and_only_marked_when_telegram_confirms(): void
    {
        $u = $this->assigned($this->agent('agent_alpha', '555000111'), ['name' => 'Ada Obi', 'email' => 'ada@example.test', 'phone' => '+2348012345678', 'balance' => 777]);
        $res = $this->submit($u)->assertCreated()->assertJsonPath('agent_notified', true);

        $this->assertNotNull(Deposit::find($res->json('deposit_id'))->agent_notified_at);
        Http::assertSent(function ($r) use ($res) {
            if ((string) ($r['chat_id'] ?? '') !== '555000111') return false;
            $t = (string) $r['text'];
            return str_contains($t, $res->json('reference')) && str_contains($t, 'Ada Obi') && str_contains($t, 'PENDING')
                && !str_contains($t, 'ada@example.test') && !str_contains($t, '+2348012345678') && !str_contains($t, '777');
        });
    }

    public function test_a_telegram_failure_never_loses_the_deposit_and_is_not_reported_as_delivered(): void
    {
        $this->fakeTelegram(Http::response(['ok' => false, 'description' => 'chat not found'], 400));
        $u = $this->assigned($this->agent('agent_alpha', '555000111'));
        $res = $this->submit($u)->assertCreated()->assertJsonPath('agent_notified', false);
        $d = Deposit::findOrFail($res->json('deposit_id'));
        $this->assertNull($d->agent_notified_at);
        $this->assertSame('pending', $d->status);
        $this->assertStringStartsWith('https://t.me/agent_alpha', $res->json('agent.link'));   // the investor can still reach the agent
    }

    public function test_http_200_with_ok_false_is_not_counted_as_delivered(): void
    {
        $this->fakeTelegram(Http::response(['ok' => false], 200));
        $u = $this->assigned($this->agent('agent_alpha', '555000111'));
        $this->submit($u)->assertCreated()->assertJsonPath('agent_notified', false);
    }

    public function test_a_connection_exception_does_not_break_the_submission(): void
    {
        $this->fakeTelegram(function () { throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: https://api.telegram.org/bot123456:TESTTOKEN/sendMessage'); });
        $u = $this->assigned($this->agent('agent_alpha', '555000111'));
        $this->submit($u)->assertCreated()->assertJsonPath('agent_notified', false);
        $this->assertSame(1, Deposit::count());
    }

    public function test_no_agent_dm_without_a_chat_id_or_for_the_default_representative(): void
    {
        config(['services.telegram.agent_username' => 'default_rep']);
        $this->submit($this->assigned($this->agent('agent_alpha', null)))->assertCreated()->assertJsonPath('agent_notified', false);
        $this->submit($this->investor())->assertCreated()->assertJsonPath('agent_notified', false);
        $this->assertSame(0, Http::recorded(fn ($r) => !in_array((string) ($r['chat_id'] ?? ''), ['-100777'], true))->count());
    }

    // ── access control ────────────────────────────────────────────────────────────

    public function test_authentication_and_role_permissions_are_unchanged(): void
    {
        $this->postJson(self::URL, ['investment_plan_id' => $this->plan->id, 'amount' => 5])->assertUnauthorized();
        $fin = User::factory()->create(['role' => 'financial', 'status' => 'active']);
        $this->submit($fin)->assertForbidden();
        $this->assertSame(0, Deposit::count());
    }

    public function test_validation_still_applies_and_saves_nothing(): void
    {
        $u = $this->investor();
        $this->submit($u, ['amount' => 0])->assertStatus(422);
        $this->submit($u, ['investment_plan_id' => 99999])->assertStatus(422);
        $this->assertSame(0, Deposit::count());
    }

    public function test_show_returns_the_agent_to_the_owner_only(): void
    {
        $u = $this->assigned($this->agent('agent_alpha'));
        $other = $this->investor();
        $id = $this->submit($u)->assertCreated()->json('deposit_id');
        $this->actingAs($u, 'sanctum')->getJson("/api/investor-investment/investor/deposits/{$id}")->assertOk()->assertJsonPath('agent.link', fn ($l) => str_starts_with($l, 'https://t.me/agent_alpha'));
        $this->actingAs($other, 'sanctum')->getJson("/api/investor-investment/investor/deposits/{$id}")->assertForbidden();
    }

    // ── financial safeguards ──────────────────────────────────────────────────────

    public function test_submission_leaves_the_deposit_pending_uncredited_and_not_self_approvable(): void
    {
        $u = $this->assigned($this->agent('agent_alpha', '555000111'));
        $d = Deposit::findOrFail($this->submit($u)->assertCreated()->json('deposit_id'));

        $this->actingAs($u, 'sanctum')->postJson("/api/financial/deposits/{$d->id}/approve")->assertForbidden();     // the investor cannot approve their own deposit
        // (The approval gate itself - payment verification, confirm_usd, etc. - is untouched and covered by FinancialTransactionsAndCommunicationsTest.)

        $this->assertSame('pending', $d->fresh()->status);
        $this->assertEquals(0, (float) $u->fresh()->balance);
        $this->assertSame(0, InvestmentAccount::count());
    }

    // ── agent set-up command / resolver ───────────────────────────────────────────

    public function test_username_normalisation_accepts_only_real_telegram_handles(): void
    {
        $this->assertSame('agent_alpha', DepositAgentResolver::normaliseUsername('@agent_alpha'));
        $this->assertSame('agent_alpha', DepositAgentResolver::normaliseUsername('https://t.me/agent_alpha'));
        foreach ([null, '', 'abcd', '1abcde', 'has space', 'a-b-c-d-e', 'https://evil.example/agent_alpha', str_repeat('a', 33)] as $bad) {
            $this->assertNull(DepositAgentResolver::normaliseUsername($bad), var_export($bad, true));
        }
    }

    public function test_command_assigns_and_validates(): void
    {
        $agent = User::factory()->create(['role' => 'financial', 'status' => 'active', 'email' => 'agent@example.test']);
        $inv = $this->investor(['email' => 'inv@example.test']);

        $this->artisan('ssi:deposit-agent', ['action' => 'telegram', 'user' => 'agent@example.test', 'value' => 'bad name'])->assertFailed();
        $this->artisan('ssi:deposit-agent', ['action' => 'telegram', 'user' => 'agent@example.test', 'value' => '@agent_alpha', '--chat-id' => '555000111'])->assertSuccessful();
        $this->artisan('ssi:deposit-agent', ['action' => 'assign', 'user' => 'inv@example.test', 'value' => 'inv@example.test'])->assertFailed();   // investor is not an agent
        $this->artisan('ssi:deposit-agent', ['action' => 'assign', 'user' => 'inv@example.test', 'value' => 'agent@example.test'])->assertSuccessful();

        $this->assertSame($agent->id, (int) $inv->fresh()->assigned_agent_id);
        $this->assertStringStartsWith('https://t.me/agent_alpha', $this->submit($inv->fresh())->assertCreated()->json('agent.link'));
        $this->artisan('ssi:deposit-agent', ['action' => 'unassign', 'user' => 'inv@example.test'])->assertSuccessful();
        $this->assertNull($inv->fresh()->assigned_agent_id);
    }
}
