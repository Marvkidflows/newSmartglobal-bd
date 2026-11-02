<?php
// LOCATION: tests/Feature/MarvflowMessagesTest.php
//
// MarvFlow dashboard → SSI Admin / SSI Financial Team messages. Same backend, same DB: a "delivered" message is a saved
// row that the SSI inbox endpoints actually return to the addressed role. Nothing here is simulated or mocked.

namespace Tests\Feature;

use App\Models\MarvflowMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $member;
    protected User $member2;
    protected User $admin;
    protected User $admin2;
    protected User $fin;
    protected User $fin2;
    protected User $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member   = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Mara Dev']);
        $this->member2  = User::factory()->create(['role' => 'marvflow_lead']);
        $this->admin    = User::factory()->create(['role' => 'admin']);
        $this->admin2   = User::factory()->create(['role' => 'admin']);
        $this->fin      = User::factory()->create(['role' => 'financial']);
        $this->fin2     = User::factory()->create(['role' => 'financial']);
        $this->investor = User::factory()->create(['role' => 'investor']);
    }

    protected function payload(array $over = []): array
    {
        return $over + ['recipient' => 'admin', 'subject' => 'Currency policy checklist', 'body' => '<p>Please review the attached plan.</p>', 'client_token' => (string) Str::uuid()];
    }

    protected function send(User $as, array $payload)
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages', $payload);
    }

    protected function alerts(User $u)
    {
        return $u->notifications()->where('data->type', 'marvflow_message')->get();
    }

    // ── The core workflow ──────────────────────────────────────────────────────

    public function test_a_message_to_the_admin_is_saved_and_appears_in_the_admin_inbox_then_is_marked_read(): void
    {
        $res = $this->send($this->member, $this->payload())->assertStatus(201);
        $res->assertJsonPath('duplicate', false)->assertJsonPath('message.status', 'sent')->assertJsonPath('message.recipient', 'admin');
        $id = $res->json('message.id');

        // persisted in the system of record, tied to the authenticated sender and the ROLE
        $row = MarvflowMessage::findOrFail($id);
        $this->assertSame($this->member->id, $row->sender_id);
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame('Currency policy checklist', $row->subject);
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->created_at);

        // the admin really sees it
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk();
        $list->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.subject', 'Currency policy checklist')
             ->assertJsonPath('data.0.sender_name', 'Mara Dev')->assertJsonPath('data.0.is_read', false);

        // sender status is still only "sent" until someone actually opens it
        $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk()->assertJsonPath('message.status', 'sent');

        // a second admin opens it: it now shows the full body and is verifiably read
        $open = $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk();
        $this->assertStringContainsString('Please review the attached plan.', $open->json('message.body_html'));
        $open->assertJsonPath('message.is_read', true)->assertJsonPath('message.read_by', $this->admin2->name);

        $sent = $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk();
        $sent->assertJsonPath('message.status', 'read');
        $this->assertStringContainsString('Read by ' . $this->admin2->name, $sent->json('message.status_label'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);
    }

    public function test_a_message_to_the_financial_team_reaches_every_active_financial_user_and_not_the_admin_inbox(): void
    {
        $suspended = User::factory()->create(['role' => 'financial', 'status' => 'suspended']);

        $id = $this->send($this->member, $this->payload(['recipient' => 'financial', 'subject' => 'Policy review']))->assertStatus(201)->json('message.id');

        foreach ([$this->fin, $this->fin2] as $f) {                                       // shared inbox: every member sees it …
            $this->actingAs($f, 'sanctum')->getJson('/api/financial/marvflow-messages')->assertOk()->assertJsonPath('data.0.id', $id);
            $this->assertCount(1, $this->alerts($f));                                    // … and is alerted
        }
        $this->assertCount(0, $this->alerts($suspended));                                // suspended accounts are not alerted
        $this->assertCount(0, $this->alerts($this->admin));                              // admins are not addressed

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(2, MarvflowMessage::find($id)->notified_count);

        // an Admin may open the Financial inbox (existing admin-oversight rule) but that never marks it "read"
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.is_read', false);
        $this->assertNull(MarvflowMessage::find($id)->read_at);

        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.is_read', true);
        $this->assertSame('read', MarvflowMessage::find($id)->status);
    }

    public function test_bell_alerts_carry_the_subject_and_a_link_but_never_the_message_body(): void
    {
        $this->send($this->member, $this->payload(['body' => '<p>SECRET-BODY-TEXT-123</p>']))->assertStatus(201);

        foreach ([$this->admin, $this->admin2] as $a) {
            $n = $this->alerts($a)->sole();
            $this->assertStringContainsString('Currency policy checklist', $n->data['message']);
            $this->assertStringNotContainsString('SECRET-BODY-TEXT-123', json_encode($n->data));
            $this->assertSame('/admin/marvflow-messages', $n->data['link']);
        }
    }

    // ── Rich text and safety ───────────────────────────────────────────────────

    public function test_subject_and_rich_text_formatting_are_preserved(): void
    {
        $html = '<p style="text-align:center;line-height:1.5;margin-bottom:16px"><strong>Bold</strong> <em>italic</em> <u>under</u> '
              . '<span style="font-size:18px">big</span></p><ol><li>one</li><li>two</li></ol><ul><li>dot</li></ul><p style="text-align:right">right — ünï</p>';
        $id = $this->send($this->member, $this->payload(['subject' => 'Ünïcode & “quotes” subject', 'body' => $html]))->assertStatus(201)->json('message.id');

        $body = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk()
            ->assertJsonPath('message.subject', 'Ünïcode & “quotes” subject')->json('message.body_html');

        foreach (['<strong>Bold</strong>', '<em>italic</em>', '<u>under</u>', 'font-size:18px', 'text-align:center', 'line-height:1.5',
                  'margin-bottom:16px', '<ol><li>one</li><li>two</li></ol>', '<ul><li>dot</li></ul>', 'text-align:right', 'right — ünï'] as $expected) {
            $this->assertStringContainsString($expected, $body, "lost: {$expected}");
        }
    }

    public function test_stored_xss_payloads_are_stripped_but_harmless_text_is_kept(): void
    {
        $evil = '<p onclick="alert(1)" style="background:url(javascript:alert(1));font-size:expression(alert(1));text-align:center">Hello'
              . '<script>alert(1)</script><img src=x onerror=alert(1)><iframe src="//evil.example"></iframe>'
              . '<a href="javascript:alert(1)">click</a><svg onload=alert(1)><circle/></svg><style>*{display:none}</style>'
              . '<span style="font-size:99px;color:red;position:fixed">x</span><!-- c --><object data="x"></object><form action="//e"><input name="a"></form>'
              . '<div onmouseover="alert(1)">tail</div></p>';
        $id = $this->send($this->member, $this->payload(['body' => $evil]))->assertStatus(201)->json('message.id');

        $stored = MarvflowMessage::find($id)->body_html;
        $served = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->json('message.body_html');

        foreach ([$stored, $served] as $html) {
            foreach (['<script', 'onerror', 'onclick', 'onload', 'onmouseover', 'javascript:', '<iframe', '<img', '<svg', '<style', '<object', '<form', '<input',
                      'expression(', 'url(', 'position', 'color:red', 'font-size:99px', '<a ', '<!--'] as $bad) {
                $this->assertStringNotContainsStringIgnoringCase($bad, $html, "must not survive: {$bad}");
            }
            $this->assertStringContainsString('Hello', $html);
            $this->assertStringContainsString('click', $html);          // link text kept, link removed
            $this->assertStringContainsString('tail', $html);
            $this->assertStringContainsString('text-align:center', $html);
        }
    }

    public function test_the_subject_is_plain_text_only(): void
    {
        $r = $this->send($this->member, $this->payload(['subject' => "<b>Hi</b>\n<script>alert(1)</script> there"]))->assertStatus(201);
        $this->assertStringNotContainsString('<', $r->json('message.subject'));
        $this->assertStringContainsString('Hi', $r->json('message.subject'));
    }

    // ── Validation and recipient integrity ────────────────────────────────────

    public function test_recipients_are_a_fixed_list_and_client_supplied_user_ids_are_ignored(): void
    {
        foreach (['investor', 'marvflow_member', 'ADMIN', 'superadmin', '1', '', null] as $bad) {
            $this->send($this->member, $this->payload(['recipient' => $bad]))->assertStatus(422)->assertJsonValidationErrors('recipient');
        }

        // smuggled ids / roles change nothing: the message is addressed to the ROLE and the sender is the authenticated user
        $id = $this->send($this->member, $this->payload(['recipient_id' => $this->investor->id, 'sender_id' => $this->admin->id, 'role' => 'investor', 'recipient_role' => 'financial']))
            ->assertStatus(201)->json('message.id');
        $row = MarvflowMessage::find($id);
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame($this->member->id, $row->sender_id);
        $this->actingAs($this->investor, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertForbidden();
    }

    public function test_empty_or_invalid_messages_are_rejected_with_clear_errors_and_nothing_is_saved(): void
    {
        $this->send($this->member, $this->payload(['body' => '<p><br></p>']))->assertStatus(422)->assertJsonValidationErrors('body');
        $this->send($this->member, $this->payload(['body' => '<script>alert(1)</script>']))->assertStatus(422)->assertJsonValidationErrors('body');
        $this->send($this->member, $this->payload(['subject' => 'ab']))->assertStatus(422)->assertJsonValidationErrors('subject');
        $this->send($this->member, $this->payload(['subject' => '<b></b>   ']))->assertStatus(422)->assertJsonValidationErrors('subject');
        $this->send($this->member, $this->payload(['client_token' => 'short']))->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->send($this->member, $this->payload(['body' => '<p>' . str_repeat('x', 30000) . '</p>']))->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertSame(0, MarvflowMessage::count());
        $this->assertSame(0, $this->alerts($this->admin)->count());
    }

    public function test_when_no_active_account_can_receive_the_message_the_user_gets_a_clear_error_and_nothing_is_saved(): void
    {
        User::where('role', 'financial')->update(['status' => 'suspended']);

        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages/recipients')->assertOk()
            ->assertJsonPath('recipients.0.key', 'admin')->assertJsonPath('recipients.0.available', true)
            ->assertJsonPath('recipients.1.key', 'financial')->assertJsonPath('recipients.1.available', false);

        $r = $this->send($this->member, $this->payload(['recipient' => 'financial']))->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->assertStringContainsString('Nothing was sent', $r->json('message'));
        $this->assertSame(0, MarvflowMessage::count());                                   // no misleading success, no row
    }

    // ── Idempotency ────────────────────────────────────────────────────────────

    public function test_retrying_the_same_compose_never_creates_a_duplicate_or_a_second_alert(): void
    {
        $p = $this->payload();
        $first  = $this->send($this->member, $p)->assertStatus(201);
        $second = $this->send($this->member, $p)->assertStatus(200);                      // e.g. the client timed out and retried
        $third  = $this->send($this->member, $p)->assertStatus(200);

        $second->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame($first->json('message.id'), $third->json('message.id'));
        $this->assertSame(1, MarvflowMessage::count());
        $this->assertCount(1, $this->alerts($this->admin));

        // a genuinely new compose (new token) is a new message; another member's identical token is also independent
        $this->send($this->member, $this->payload())->assertStatus(201);
        $this->send($this->member2, $p)->assertStatus(201);
        $this->assertSame(3, MarvflowMessage::count());
    }

    // ── Authentication & authorisation ─────────────────────────────────────────

    public function test_only_authenticated_marvflow_users_can_send_and_nobody_can_read_other_inboxes(): void
    {
        $id = $this->send($this->member, $this->payload())->assertStatus(201)->json('message.id');
        $this->send($this->member, $this->payload(['recipient' => 'financial']))->assertStatus(201);

        // not signed in
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/marvflow/messages', $this->payload())->assertUnauthorized();
        $this->getJson('/api/admin/marvflow-messages')->assertUnauthorized();
        $this->getJson('/api/financial/marvflow-messages')->assertUnauthorized();

        // wrong roles cannot send as MarvFlow
        foreach ([$this->admin, $this->fin, $this->investor] as $u) {
            $this->send($u, $this->payload())->assertForbidden();
        }

        // MarvFlow users and investors cannot read either SSI inbox
        foreach ([$this->member, $this->member2, $this->investor] as $u) {
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson('/api/financial/marvflow-messages')->assertForbidden();
        }

        // Financial users cannot read the Admin inbox
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertForbidden();
        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertNotFound();   // an Admin-addressed message is not in the Financial inbox
    }

    public function test_a_member_only_sees_and_opens_their_own_sent_messages(): void
    {
        $mine   = $this->send($this->member, $this->payload())->assertStatus(201)->json('message.id');
        $theirs = $this->send($this->member2, $this->payload())->assertStatus(201)->json('message.id');

        $ids = collect($this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine], $ids);
        $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/messages/{$theirs}")->assertNotFound();
    }

    // ── Existing SSI messaging keeps working ──────────────────────────────────

    public function test_existing_ssi_messaging_is_unaffected(): void
    {
        // Admin <-> Financial staff channel
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => 'Hello Financial'])->assertStatus(201);
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/staff-messages')->assertOk()->assertJsonPath('messages.0.body', 'Hello Financial');

        // SSI -> MarvFlow requests list still loads for both SSI roles
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/dev-requests')->assertOk();
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/dev-requests')->assertOk();
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/requests')->assertOk();

        // sending a MarvFlow message did not touch those tables
        $this->send($this->member, $this->payload())->assertStatus(201);
        $this->assertSame(1, \App\Models\StaffMessage::count());
    }
}
