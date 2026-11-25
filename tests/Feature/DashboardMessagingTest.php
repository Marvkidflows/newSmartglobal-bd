<?php
// LOCATION: tests/Feature/DashboardMessagingTest.php
//
// Investor <-> Financial Team conversation inside the dashboard, and the deposit enquiry that feeds it. Everything runs on the EXISTING
// `messages` table / controllers; these tests pin the new behaviour (agent routing, deposit tagging, unanswered status, duplicate
// protection, notification links, isolation) AND that old messages and deposit records are untouched.
//
// NOT RUN by the author (no vendor/ and no network in the build sandbox) - run:  php artisan test --filter=DashboardMessagingTest

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\InvestmentPlan;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Rank\RankTestCase;

class DashboardMessagingTest extends RankTestCase
{
    protected InvestmentPlan $plan;
    protected const DEPOSIT_URL = '/api/investor-investment/investor/deposits/initiate';
    protected const CHAT_URL    = '/api/investor-investment/messages';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = InvestmentPlan::create(['name' => 'Starter', 'min_amount' => 1, 'profit_percentage' => 10, 'profit_percent' => 10, 'duration_days' => 30, 'status' => 'active']);
        config(['financial.ledger_enabled' => true, 'services.telegram.bot_token' => null, 'services.telegram.chat_id' => null, 'services.telegram.agent_username' => null]);
        Http::fake();   // no Telegram, no network: the whole workflow must work without it
    }

    protected function staff(string $role = 'financial', array $o = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'status' => 'active'], $o));
    }

    protected function as(User $u) { return $this->actingAs($u, 'sanctum'); }

    /**
     * Make the NEXT request a genuine guest request. actingAs() sets the user on the cached sanctum guard and that guard instance
     * lives for the whole test method, so without this a "guest" request after an authenticated one is silently still logged in.
     * (The routes themselves are protected by auth:sanctum; this only resets the test client.)
     */
    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse(config('auth.defaults.guard'));
        return $this;
    }

    protected function token(): string { return bin2hex(random_bytes(12)); }

    protected function chat(User $investor, array $o = [])
    {
        return $this->as($investor)->postJson(self::CHAT_URL, array_merge(['department' => 'financial', 'body' => 'How do I fund my account?'], $o));
    }

    protected function deposit(User $investor, array $o = [])
    {
        return $this->as($investor)->postJson(self::DEPOSIT_URL, array_merge(['investment_plan_id' => $this->plan->id, 'amount' => 500], $o));
    }

    protected function unreadAlerts(User $u, ?string $type = null)
    {
        $q = $u->fresh()->unreadNotifications;
        return $type ? $q->filter(fn ($n) => ($n->data['type'] ?? null) === $type) : $q;
    }

    // ── 1-4: deposit section -> enquiry inside the existing messaging system ────────────────────────────────

    public function test_a_deposit_request_opens_one_deposit_enquiry_in_the_existing_conversation(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();

        $res = $this->deposit($inv)->assertCreated()->assertJsonPath('conversation.available', true);

        $this->assertSame(1, Deposit::count(), 'exactly one deposit record');
        $msg = Message::firstOrFail();
        $this->assertSame('deposit', $msg->topic);
        $this->assertSame('financial', $msg->department);
        $this->assertSame('investor', $msg->initiated_by);
        $this->assertSame($inv->id, $msg->investor_id);
        $this->assertSame($res->json('deposit_id'), $msg->deposit_id);
        $this->assertStringContainsString($res->json('reference'), $msg->body);
        $this->assertFalse((bool) $msg->read_by_financial);
        $this->assertSame($fin->id, $msg->receiver_id);
    }

    public function test_a_retried_deposit_submission_creates_neither_a_second_deposit_nor_a_second_message(): void
    {
        $this->staff();
        $inv = $this->investor();
        $key = $this->token() . $this->token();

        $this->deposit($inv, ['idempotency_key' => $key])->assertCreated();
        $this->deposit($inv, ['idempotency_key' => $key])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('conversation.available', true);

        $this->assertSame(1, Deposit::count());
        $this->assertSame(1, Message::count());
    }

    public function test_the_deposit_still_saves_when_there_is_no_active_staff_member_to_receive_the_enquiry(): void
    {
        $inv = $this->investor();
        // RankTestCase::setUp() always creates one ACTIVE admin, and an active admin is a legitimate fallback recipient. This test is about
        // "no ACTIVE staff", so every Financial Team / admin account is made inactive first (they still exist: nothing may be assigned to them).
        User::whereIn('role', ['financial', 'admin'])->update(['status' => 'suspended']);

        $this->deposit($inv)->assertCreated()->assertJsonPath('conversation.available', false);
        $this->assertSame(1, Deposit::count());
        $this->assertSame('pending', Deposit::first()->status);
        $this->assertSame(0, Message::count(), 'no enquiry is addressed to an inactive account');
    }

    public function test_the_deposit_enquiry_works_with_telegram_completely_unconfigured(): void
    {
        $this->staff();
        $this->deposit($this->investor())->assertCreated()->assertJsonPath('agent', null);
        Http::assertNothingSent();
        $this->assertSame(1, Message::where('topic', 'deposit')->count());
    }

    // ── 5-6: routing to the assigned agent / the whole team ─────────────────────────────────────────────

    public function test_a_message_goes_to_the_assigned_agent_only_and_alerts_only_that_agent(): void
    {
        $agent = $this->staff('financial', ['name' => 'Agent A']);
        $other = $this->staff('financial', ['name' => 'Agent B']);
        $inv   = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $agent->id])->save();

        $this->chat($inv)->assertCreated();

        $this->assertSame($agent->id, Message::firstOrFail()->receiver_id);
        $this->assertCount(1, $this->unreadAlerts($agent, 'investor_message'));
        $this->assertCount(0, $this->unreadAlerts($other, 'investor_message'));
    }

    public function test_an_unassigned_investor_alerts_every_active_financial_member_but_not_inactive_ones(): void
    {
        $a = $this->staff(); $b = $this->staff();
        $gone = $this->staff('financial', ['status' => 'suspended']);
        $inv = $this->investor();

        $this->chat($inv)->assertCreated();

        $this->assertCount(1, $this->unreadAlerts($a, 'investor_message'));
        $this->assertCount(1, $this->unreadAlerts($b, 'investor_message'));
        $this->assertCount(0, $this->unreadAlerts($gone, 'investor_message'));
    }

    public function test_an_assignment_to_an_inactive_agent_falls_back_to_the_team(): void
    {
        $gone = $this->staff('financial', ['status' => 'suspended']);
        $team = $this->staff();
        $inv  = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $gone->id])->save();

        $this->chat($inv)->assertCreated();
        $this->assertCount(1, $this->unreadAlerts($team, 'investor_message'));
    }

    public function test_the_alert_deep_links_to_the_right_conversation_and_names_the_deposit(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $res = $this->deposit($inv)->assertCreated();

        $alert = $this->unreadAlerts($fin, 'investor_message')->first();
        $this->assertNotNull($alert);
        $this->assertSame('/financial/messages?investor=' . $inv->id, $alert->data['link']);
        $this->assertSame($res->json('deposit_id'), $alert->data['deposit_id']);
        $this->assertSame('deposit', $alert->data['topic']);
    }

    public function test_the_investor_can_see_who_they_are_talking_to_by_name_only(): void
    {
        $agent = $this->staff('financial', ['name' => 'Agent A', 'telegram_username' => 'agent_alpha', 'telegram_chat_id' => '999']);
        $inv   = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $agent->id])->save();

        $json = $this->as($inv)->getJson(self::CHAT_URL)->assertOk()->assertJsonPath('agent.name', 'Agent A')->json();
        $this->assertSame(['name'], array_keys($json['agent']));
        $this->assertStringNotContainsString('999', json_encode($json));
    }

    // ── 7: duplicate protection ─────────────────────────────────────────────────────────────────────────

    public function test_the_same_send_twice_returns_the_same_message_once(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $t = $this->token() . 'abcd';

        $first  = $this->chat($inv, ['client_token' => $t])->assertCreated()->json('data.id');
        $second = $this->chat($inv, ['client_token' => $t])->assertOk()->assertJsonPath('duplicate', true)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Message::count());
        $this->assertCount(1, $this->unreadAlerts($fin, 'investor_message'), 'no second alert');
    }

    public function test_a_staff_reply_retry_is_also_stored_once(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $t = $this->token() . 'abcd';
        $url = "/api/financial/messages/{$inv->id}/send";

        $this->as($fin)->postJson($url, ['body' => 'Hello', 'client_token' => $t])->assertCreated();
        $this->as($fin)->postJson($url, ['body' => 'Hello', 'client_token' => $t])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Message::count());
        $this->assertCount(1, $this->unreadAlerts($inv));
    }

    // ── validation / ownership ──────────────────────────────────────────────────────────────────────────

    public function test_an_enquiry_cannot_reference_another_investors_deposit(): void
    {
        $this->staff();
        $victim = $this->investor();
        $other  = $this->investor();
        $dep = Deposit::create(['user_id' => $victim->id, 'investment_plan_id' => $this->plan->id, 'amount' => 100, 'payment_method' => 'pending', 'transaction_reference' => 'DEP-X', 'status' => 'pending']);

        $this->chat($other, ['deposit_id' => $dep->id, 'topic' => 'deposit'])->assertStatus(404);
        $this->assertSame(0, Message::count());
    }

    public function test_an_enquiry_about_my_own_deposit_is_tagged_and_shows_the_reference_to_me(): void
    {
        $this->staff();
        $inv = $this->investor();
        $dep = Deposit::create(['user_id' => $inv->id, 'investment_plan_id' => $this->plan->id, 'amount' => 100, 'payment_method' => 'pending', 'transaction_reference' => 'DEP-MINE', 'status' => 'pending']);

        $this->chat($inv, ['deposit_id' => $dep->id])->assertCreated()->assertJsonPath('data.topic', 'deposit')->assertJsonPath('data.deposit.reference', 'DEP-MINE');
    }

    public function test_bad_input_is_rejected_before_anything_is_stored(): void
    {
        $this->staff();
        $inv = $this->investor();
        $this->chat($inv, ['body' => '   '])->assertStatus(422);
        $this->chat($inv, ['client_token' => 'short'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->chat($inv, ['topic' => 'other'])->assertStatus(422)->assertJsonValidationErrors('topic');
        $this->assertSame(0, Message::count());
    }

    public function test_with_no_financial_team_at_all_the_chat_says_so_and_stores_nothing(): void
    {
        $this->chat($this->investor())->assertStatus(503);
        $this->assertSame(0, Message::count());
    }

    // ── 8-10: Financial Team inbox ──────────────────────────────────────────────────────────────────────

    public function test_the_inbox_identifies_deposit_conversations_unread_and_unanswered_and_the_assignment(): void
    {
        $fin = $this->staff('financial', ['name' => 'Agent A']);
        $inv = $this->investor(['name' => 'Ivy Investor']);
        $inv->forceFill(['assigned_agent_id' => $fin->id])->save();
        $this->deposit($inv)->assertCreated();

        $row = $this->as($fin)->getJson('/api/financial/messages')->assertOk()->assertJsonPath('total_unread', 1)->assertJsonPath('total_unanswered', 1)->json('conversations.0');
        $this->assertSame($inv->id, $row['investor']['id']);
        $this->assertTrue($row['has_deposit_enquiry']);
        $this->assertSame('awaiting_reply', $row['status']);
        $this->assertSame(1, $row['unread_count']);
        $this->assertSame('Agent A', $row['assigned_agent']['name']);
    }

    public function test_inbox_filters_unanswered_deposit_and_mine(): void
    {
        $me = $this->staff(); $them = $this->staff();
        $a = $this->investor(); $b = $this->investor(); $c = $this->investor();
        $a->forceFill(['assigned_agent_id' => $me->id])->save();
        $this->deposit($a)->assertCreated();                 // a: deposit, assigned to me, unanswered
        $this->chat($b)->assertCreated();                    // b: general, unanswered
        $this->chat($c)->assertCreated();                    // c: general, then answered
        $this->as($them)->postJson("/api/financial/messages/{$c->id}/send", ['body' => 'Done'])->assertCreated();

        $ids = fn (string $f, User $as) => collect($this->as($as)->getJson("/api/financial/messages?filter={$f}")->assertOk()->json('conversations'))->pluck('investor.id')->sort()->values()->all();

        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $ids('unanswered', $me));
        $this->assertSame([$a->id], $ids('deposit', $me));
        $this->assertSame([$a->id], $ids('mine', $me));
        $this->assertSame([], $ids('mine', $them));
    }

    public function test_opening_a_conversation_marks_it_read_and_replying_marks_it_answered(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $this->deposit($inv)->assertCreated();

        $this->as($fin)->getJson("/api/financial/messages/{$inv->id}")->assertOk()->assertJsonPath('status', 'awaiting_reply')->assertJsonPath('thread.0.topic', 'deposit');
        $this->as($fin)->getJson('/api/financial/messages/unread-count')->assertJsonPath('unread', 0)->assertJsonPath('unanswered', 1);

        $this->as($fin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Please use the bank details below.'])->assertCreated();
        $this->as($fin)->getJson("/api/financial/messages/{$inv->id}")->assertJsonPath('status', 'answered');
    }

    public function test_a_reply_to_a_deposit_enquiry_stays_a_deposit_message_linked_to_the_deposit(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $depId = $this->deposit($inv)->assertCreated()->json('deposit_id');

        $this->as($fin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Instructions...'])->assertCreated()->assertJsonPath('data.topic', 'deposit')->assertJsonPath('data.deposit.id', $depId);
    }

    // ── 11-13: investor notification + reading + history ────────────────────────────────────────────────

    public function test_the_investor_is_notified_with_a_link_reads_the_reply_and_keeps_the_history(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $this->deposit($inv)->assertCreated();
        $this->as($fin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Pay to the account below.'])->assertCreated();

        $alert = $this->unreadAlerts($inv)->first();
        $this->assertSame('financial_message', $alert->data['type']);
        $this->assertSame('/investor/messages', $alert->data['link']);

        $this->as($inv)->getJson(self::CHAT_URL . '/unread-count')->assertJsonPath('unread_count', 1);

        $thread = $this->as($inv)->getJson(self::CHAT_URL)->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('financial_status', 'answered')->json('thread');
        $this->assertCount(2, $thread);
        $this->assertSame('deposit', $thread[0]['topic']);
        $this->assertSame('Pay to the account below.', $thread[1]['body']);
        $this->as($inv)->getJson(self::CHAT_URL . '/unread-count')->assertJsonPath('unread_count', 0);

        // both sides continue in the SAME conversation, and history stays
        $this->chat($inv, ['body' => 'Paid, thanks.'])->assertCreated();
        $this->as($fin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Received.'])->assertCreated();
        $this->assertCount(4, $this->as($inv)->getJson(self::CHAT_URL)->json('thread'));
        $this->assertCount(4, $this->as($fin)->getJson("/api/financial/messages/{$inv->id}")->json('thread'));
    }

    // ── security ────────────────────────────────────────────────────────────────────────────────────────

    public function test_an_investor_only_sees_their_own_conversation(): void
    {
        $this->staff();
        $a = $this->investor(); $b = $this->investor();
        $this->chat($a, ['body' => 'A private question'])->assertCreated();
        $msgId = Message::firstOrFail()->id;

        $this->assertSame([], $this->as($b)->getJson(self::CHAT_URL)->assertOk()->json('thread'));
        $this->as($b)->getJson(self::CHAT_URL . "/{$msgId}")->assertForbidden();
        $this->assertStringNotContainsString('A private question', $this->as($b)->getJson(self::CHAT_URL)->getContent());
    }

    public function test_investors_cannot_use_staff_endpoints_and_guests_cannot_use_anything(): void
    {
        $inv = $this->investor();
        $other = $this->investor();
        $this->as($inv)->getJson('/api/financial/messages')->assertForbidden();
        $this->as($inv)->getJson("/api/financial/messages/{$other->id}")->assertForbidden();
        $this->as($inv)->postJson("/api/financial/messages/{$other->id}/send", ['body' => 'x'])->assertForbidden();

        $this->asGuest()->getJson(self::CHAT_URL)->assertUnauthorized();
        $this->asGuest()->postJson(self::CHAT_URL, ['body' => 'x'])->assertUnauthorized();
        $this->asGuest()->getJson('/api/investor-investment/investor/deposits')->assertUnauthorized();
        $this->asGuest()->postJson(self::DEPOSIT_URL, ['investment_plan_id' => $this->plan->id, 'amount' => 5])->assertUnauthorized();
    }

    public function test_message_ownership_is_taken_from_the_session_not_the_request(): void
    {
        $this->staff();
        $inv = $this->investor(); $victim = $this->investor();
        $this->chat($inv, ['sender_id' => $victim->id, 'investor_id' => $victim->id, 'initiated_by' => 'admin', 'receiver_id' => $victim->id])->assertCreated();

        $m = Message::firstOrFail();
        $this->assertSame($inv->id, $m->sender_id);
        $this->assertSame($inv->id, $m->investor_id);
        $this->assertSame('investor', $m->initiated_by);
        $this->assertNotNull($m->created_at);
    }

    public function test_script_markup_in_a_message_is_sanitised_before_storage(): void
    {
        $this->staff();
        $inv = $this->investor();
        $this->chat($inv, ['body' => null, 'body_html' => '<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(2)>'])->assertCreated();
        $m = Message::firstOrFail();
        $this->assertStringNotContainsString('<script', (string) $m->body_html);
        $this->assertStringNotContainsString('onerror', (string) $m->body_html);
    }

    // ── preservation ────────────────────────────────────────────────────────────────────────────────────

    public function test_messages_written_before_this_change_still_read_as_general_conversation(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        // a row exactly as the old code wrote it: none of the new columns set
        $old = Message::create(['sender_id' => $inv->id, 'receiver_id' => $fin->id, 'investor_id' => $inv->id, 'subject' => 'Old', 'body' => 'Legacy question',
            'initiated_by' => 'investor', 'department' => 'financial', 'read_by_admin' => true, 'read_by_financial' => false, 'read_by_investor' => true]);

        $this->assertSame('general', $old->fresh()->topic);
        $this->assertNull($old->fresh()->deposit_id);
        $this->assertNull($old->fresh()->client_token);
        $row = $this->as($inv)->getJson(self::CHAT_URL)->assertOk()->json('thread.0');
        $this->assertSame('Legacy question', $row['body']);
        $this->assertSame('general', $row['topic']);
        $this->assertNull($row['deposit']);
        // two legacy rows without a token never collide with each other
        Message::create(['sender_id' => $inv->id, 'receiver_id' => $fin->id, 'investor_id' => $inv->id, 'body' => 'Second legacy', 'initiated_by' => 'investor', 'department' => 'financial']);
        $this->assertSame(2, Message::count());
    }

    public function test_deposit_records_and_the_pending_state_are_unchanged_by_the_enquiry(): void
    {
        $this->staff();
        $inv = $this->investor();
        $before = $inv->fresh()->balance;

        $res = $this->deposit($inv)->assertCreated();
        $dep = Deposit::findOrFail($res->json('deposit_id'));

        $this->assertSame('pending', $dep->status);
        $this->assertSame((float) 500, (float) $dep->amount);
        $this->assertSame($res->json('reference'), $dep->transaction_reference);
        $this->assertEquals($before, $inv->fresh()->balance, 'submitting an enquiry never credits anything');
        $this->as($inv)->getJson('/api/investor-investment/investor/deposits')->assertOk()->assertJsonCount(1, 'deposits');
    }

    // ── review follow-ups ───────────────────────────────────────────────────────────────────────────────

    public function test_a_simultaneous_duplicate_staff_reply_returns_the_existing_message_not_an_error(): void
    {
        $fin = $this->staff();
        $inv = $this->investor();
        $tok = $this->token() . 'race';

        // Simulate the race: after the controller's pre-check (which sees nothing) and before its insert, a competing request
        // commits a row with the SAME token. The listener fires on the controller's "latest investor message" lookup, which runs
        // just before the transaction starts, so the competitor survives the failed insert's rollback.
        $fired = false;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$fired, $fin, $inv, $tok) {
            if ($fired || !str_contains($q->sql, 'initiated_by') || !str_contains($q->sql, 'order by')) return;
            $fired = true;
            \Illuminate\Support\Facades\DB::table('messages')->insert([
                'sender_id' => $fin->id, 'receiver_id' => $inv->id, 'investor_id' => $inv->id, 'body' => 'Winner', 'initiated_by' => 'admin',
                'department' => 'financial', 'topic' => 'general', 'client_token' => $tok, 'read_by_admin' => 1, 'read_by_financial' => 1,
                'read_by_investor' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $res = $this->as($fin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Loser', 'client_token' => $tok])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.body', 'Winner');

        $this->assertTrue($fired, 'the race was actually simulated');
        $this->assertSame(1, Message::count());
        $this->assertCount(0, $this->unreadAlerts($inv), 'the losing request sends no second notification');
    }

    public function test_an_admin_who_is_the_assigned_agent_is_alerted_with_a_link_that_opens_the_financial_conversation(): void
    {
        $admin = $this->staff('admin', ['name' => 'Ada Admin']);
        $inv   = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $admin->id])->save();

        $this->chat($inv)->assertCreated();

        $alert = $this->unreadAlerts($admin, 'investor_message')->first();
        $this->assertNotNull($alert);
        $this->assertSame('/financial/messages?investor=' . $inv->id, $alert->data['link']);

        // ...and that link's data endpoints accept the admin and answer with the Financial Team identity
        $this->as($admin)->getJson("/api/financial/messages/{$inv->id}")->assertOk()->assertJsonPath('assigned_agent.id', $admin->id);
        $this->as($admin)->postJson("/api/financial/messages/{$inv->id}/send", ['body' => 'Hello'])->assertCreated()
            ->assertJsonPath('data.sender', 'Smart System Investment — Financial Team');
    }

    // ── Telegram heads-up for staff (optional extra; the dashboard alert is the main one) ───────────────

    /**
     * Install THIS test's fake Telegram reply. This class's setUp() already runs a catch-all Http::fake() (empty 200 body) and Laravel
     * uses the FIRST matching stub, so a plain Http::fake() here would be silently ignored and Telegram's reply would read as empty
     * (an agent DM could then never be "confirmed"). Starting from a fresh HTTP client makes the reply below the one that is really used.
     */
    protected function fakeTelegram($response): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telegram.org/*' => $response]);
    }

    protected function telegramOn(bool $ok = true): void
    {
        config(['services.telegram.bot_token' => 'TESTTOKEN', 'services.telegram.chat_id' => '-100STAFF', 'services.telegram.financial_chat_id' => null]);
        $this->fakeTelegram(Http::response(['ok' => $ok]));
    }

    /** chat_id + text of every Telegram sendMessage request made so far */
    protected function telegramSent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => ['chat' => (string) $p[0]['chat_id'] ?? '', 'text' => (string) ($p[0]['text'] ?? '')])->values()->all();
    }

    public function test_an_investor_message_pings_the_staff_chat_on_telegram_with_the_request(): void
    {
        $this->staff();
        $this->telegramOn();
        $inv = $this->investor(['name' => 'Ivy Investor']);

        $this->chat($inv, ['body' => 'How do I fund my account?'])->assertCreated();

        $sent = $this->telegramSent();
        $this->assertCount(1, $sent);
        $this->assertSame('-100STAFF', $sent[0]['chat']);
        $this->assertStringContainsString('New investor message', $sent[0]['text']);
        $this->assertStringContainsString('Ivy Investor', $sent[0]['text']);
        $this->assertStringContainsString('How do I fund my account?', $sent[0]['text']);
        $this->assertStringNotContainsString($inv->email, $sent[0]['text'], 'no email address in the alert');
    }

    public function test_a_deposit_enquiry_sent_from_the_chat_is_labelled_with_its_reference(): void
    {
        $this->staff();
        $this->telegramOn();
        $inv = $this->investor();
        $dep = Deposit::create(['user_id' => $inv->id, 'investment_plan_id' => $this->plan->id, 'amount' => 100, 'payment_method' => 'pending', 'transaction_reference' => 'DEP-TG1', 'status' => 'pending']);

        $this->chat($inv, ['deposit_id' => $dep->id])->assertCreated();

        $this->assertStringContainsString('New deposit enquiry', $this->telegramSent()[0]['text']);
        $this->assertStringContainsString('DEP-TG1', $this->telegramSent()[0]['text']);
    }

    public function test_an_assigned_agent_with_a_chat_id_is_messaged_directly_and_the_staff_chat_is_not(): void
    {
        $agent = $this->staff('financial', ['telegram_chat_id' => '555111']);
        $this->telegramOn();
        $inv = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $agent->id])->save();

        $this->chat($inv)->assertCreated();

        $this->assertSame(['555111'], array_column($this->telegramSent(), 'chat'));
    }

    public function test_if_the_agent_dm_is_not_confirmed_the_staff_chat_is_used_so_nothing_is_missed(): void
    {
        $agent = $this->staff('financial', ['telegram_chat_id' => '555111']);
        config(['services.telegram.bot_token' => 'TESTTOKEN', 'services.telegram.chat_id' => '-100STAFF']);
        $this->fakeTelegram(Http::sequence()->push(['ok' => false], 200)->push(['ok' => true], 200));
        $inv = $this->investor();
        $inv->forceFill(['assigned_agent_id' => $agent->id])->save();

        $this->chat($inv)->assertCreated();

        $this->assertSame(['555111', '-100STAFF'], array_column($this->telegramSent(), 'chat'));
    }

    public function test_the_deposit_flow_does_not_add_a_second_telegram_ping_for_its_enquiry(): void
    {
        $this->staff();
        $this->telegramOn();
        $this->deposit($this->investor())->assertCreated();

        foreach ($this->telegramSent() as $m) {
            $this->assertStringNotContainsString('New deposit enquiry', $m['text']);
        }
        $this->assertSame(1, Message::where('topic', 'deposit')->count(), 'the in-dashboard enquiry is still created');
    }

    public function test_a_retried_send_pings_telegram_once(): void
    {
        $this->staff();
        $this->telegramOn();
        $inv = $this->investor();
        $t = $this->token() . 'tg';

        $this->chat($inv, ['client_token' => $t])->assertCreated();
        $this->chat($inv, ['client_token' => $t])->assertOk();

        $this->assertCount(1, $this->telegramSent());
    }

    public function test_the_message_is_saved_and_notified_in_app_when_telegram_is_down_or_unconfigured(): void
    {
        $fin = $this->staff();
        config(['services.telegram.bot_token' => 'TESTTOKEN', 'services.telegram.chat_id' => '-100STAFF']);
        $this->fakeTelegram(Http::response('boom', 500));

        $this->chat($this->investor())->assertCreated();
        $this->assertSame(1, Message::count());
        $this->assertCount(1, $this->unreadAlerts($fin, 'investor_message'));

        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        $this->chat($this->investor())->assertCreated();
        $this->assertSame(2, Message::count());
    }
}
