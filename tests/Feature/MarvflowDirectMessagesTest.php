<?php
// LOCATION: tests/Feature/MarvflowDirectMessagesTest.php
//
// "Message Admin" quick messages + the upgraded professional-email formatting, through the real endpoints.
// Quick messages reuse the SAME table, inbox, read tracking, idempotency and alerts as formal email (kind = 'direct').

namespace Tests\Feature;

use App\Models\MarvflowMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowDirectMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $member;
    protected User $lead;
    protected User $admin;
    protected User $admin2;
    protected User $fin;
    protected User $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member   = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Mara Dev']);
        $this->lead     = User::factory()->create(['role' => 'marvflow_lead', 'name' => 'Lee Lead']);
        $this->admin    = User::factory()->create(['role' => 'admin']);
        $this->admin2   = User::factory()->create(['role' => 'admin']);
        $this->fin      = User::factory()->create(['role' => 'financial']);
        $this->investor = User::factory()->create(['role' => 'investor']);
    }

    protected function quick(User $as, array $over = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages/direct', $over + ['body' => 'Quick question about the policy', 'client_token' => (string) Str::uuid()]);
    }

    protected function alerts(User $u)
    {
        return $u->notifications()->where('data->type', 'marvflow_message')->get();
    }

    // ── Message Admin: the happy path ─────────────────────────────────────────

    public function test_a_member_and_a_lead_can_send_a_quick_message_that_every_admin_can_open(): void
    {
        foreach ([$this->member, $this->lead] as $sender) {
            $res = $this->quick($sender, ['body' => "Line one\nLine two"])->assertStatus(201);
            $res->assertJsonPath('duplicate', false)->assertJsonPath('message.kind', 'direct')->assertJsonPath('message.recipient', 'admin')
                ->assertJsonPath('message.status', 'sent');

            $row = MarvflowMessage::findOrFail($res->json('message.id'));
            $this->assertSame($sender->id, $row->sender_id);               // sender comes from the session
            $this->assertSame('admin', $row->recipient_role);
            $this->assertSame('direct', $row->kind);
            $this->assertSame('', $row->subject);                          // no subject workflow for a quick message
            $this->assertSame("Line one\nLine two", $row->body_text);
        }

        // both admins see both messages, labelled as quick messages
        foreach ([$this->admin, $this->admin2] as $a) {
            $list = $this->actingAs($a, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk();
            $list->assertJsonCount(2, 'data')->assertJsonPath('unread_count', 2)->assertJsonPath('data.0.kind', 'direct')
                 ->assertJsonPath('data.0.kind_label', 'Quick message')->assertJsonPath('data.0.subject', 'Quick message');
            $this->assertCount(2, $this->alerts($a));
        }

        // the Financial inbox never receives them
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/marvflow-messages')->assertOk()->assertJsonCount(0, 'data');
        $this->assertCount(0, $this->alerts($this->fin));
    }

    public function test_the_sender_sees_it_in_their_history_with_status_that_becomes_read_only_when_an_admin_opens_it(): void
    {
        $id = $this->quick($this->member)->assertStatus(201)->json('message.id');
        $this->quick($this->lead);                                          // someone else's quick message

        $hist = $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk();
        $hist->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'sent');
        $this->assertStringContainsString('Sent — saved in the SSI System Admin inbox', $hist->json('data.0.status_label'));

        // an admin merely LISTING it does not mark it read …
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk();
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertJsonPath('data.0.status', 'sent');

        // … opening it does, and the sender sees who read it
        $open = $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk();
        $open->assertJsonPath('message.is_read', true)->assertJsonPath('message.kind', 'direct');
        $this->assertStringContainsString('Quick question about the policy', $open->json('message.body_html'));

        $after = $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk();
        $after->assertJsonPath('data.0.status', 'read');
        $this->assertStringContainsString('Read by ' . $this->admin2->name, $after->json('data.0.status_label'));
    }

    public function test_history_can_be_filtered_by_kind_and_email_is_unaffected(): void
    {
        $this->quick($this->member);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/marvflow/messages', ['recipient' => 'admin', 'subject' => 'Formal subject', 'body' => '<p>Formal</p>', 'client_token' => (string) Str::uuid()])->assertStatus(201);

        $c = fn (string $q) => count($this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages' . $q)->assertOk()->json('data'));
        $this->assertSame(2, $c(''));
        $this->assertSame(1, $c('?kind=direct'));
        $this->assertSame(1, $c('?kind=email'));
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=bogus')->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages?kind=email')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Formal subject');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages?kind=direct')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread_by_kind.direct', 1)->assertJsonPath('unread_by_kind.email', 1);
    }

    // ── Plain text, validation, safety ────────────────────────────────────────

    public function test_quick_messages_are_plain_text_so_markup_is_stored_as_inert_text(): void
    {
        $id = $this->quick($this->member, ['body' => '<script>alert(1)</script> <b onclick="x()">bold?</b> & "quotes"'])->assertStatus(201)->json('message.id');

        $html = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->json('message.body_html');
        // No element survives: every '<' of the typed markup is escaped, so it can only ever display as text.
        $this->assertSame(1, substr_count($html, '<p>'));
        $this->assertSame(0, preg_match('/<(?!\/?p>|br\s*\/?>)/i', $html), 'only the wrapping <p>/<br> may be real tags');
        $this->assertStringContainsString('&lt;script&gt;', $html);          // shown to the admin as TEXT, never executed
        $this->assertStringContainsString('bold?', $html);
    }

    public function test_empty_oversized_and_malformed_quick_messages_are_rejected_and_nothing_is_saved(): void
    {
        $this->quick($this->member, ['body' => "   \n\t "])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->quick($this->member, ['body' => "\x00\x01\x02"])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->quick($this->member, ['body' => str_repeat('x', 2001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->quick($this->member, ['client_token' => 'short'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->actingAs($this->member, 'sanctum')->postJson('/api/marvflow/messages/direct', ['client_token' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('body');

        $this->quick($this->member, ['body' => str_repeat('x', 2000)])->assertStatus(201);       // the limit itself is allowed
        $this->assertSame(1, MarvflowMessage::count());
    }

    public function test_the_client_cannot_choose_the_recipient_sender_or_role(): void
    {
        $id = $this->quick($this->member, ['recipient' => 'financial', 'recipient_role' => 'financial', 'recipient_id' => $this->fin->id,
            'sender_id' => $this->admin->id, 'role' => 'admin', 'kind' => 'email', 'subject' => 'Injected'])->assertStatus(201)->json('message.id');

        $row = MarvflowMessage::find($id);
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame($this->member->id, $row->sender_id);
        $this->assertSame('direct', $row->kind);
        $this->assertSame('', $row->subject);
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/marvflow-messages')->assertJsonCount(0, 'data');
    }

    public function test_alerts_for_quick_messages_never_contain_the_message_text(): void
    {
        $this->quick($this->member, ['body' => 'SECRET-QUICK-TEXT-777']);

        foreach ([$this->admin, $this->admin2] as $a) {
            $n = $this->alerts($a)->sole();
            $this->assertStringContainsString('Mara Dev', $n->data['message']);
            $this->assertStringNotContainsString('SECRET-QUICK-TEXT-777', json_encode($n->data));
            $this->assertSame('/admin/marvflow-messages', $n->data['link']);
        }
    }

    // ── Retries, availability ─────────────────────────────────────────────────

    public function test_a_retried_quick_message_is_not_duplicated_and_raises_no_second_alert(): void
    {
        $p = ['body' => 'Retry me', 'client_token' => (string) Str::uuid()];
        $first  = $this->quick($this->member, $p)->assertStatus(201);
        $second = $this->quick($this->member, $p)->assertStatus(200)->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame(1, MarvflowMessage::count());
        $this->assertCount(1, $this->alerts($this->admin));
        $this->quick($this->member, ['body' => 'Retry me', 'client_token' => (string) Str::uuid()])->assertStatus(201);   // a new compose is a new message
        $this->assertSame(2, MarvflowMessage::count());
    }

    public function test_with_no_active_admin_the_user_gets_a_clear_error_and_nothing_is_saved(): void
    {
        User::where('role', 'admin')->update(['status' => 'suspended']);

        $r = $this->quick($this->member)->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->assertStringContainsString('Nothing was sent', $r->json('message'));
        $this->assertSame(0, MarvflowMessage::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // ── Authorisation ─────────────────────────────────────────────────────────

    public function test_only_marvflow_users_can_send_and_unauthorised_roles_cannot_read(): void
    {
        $id = $this->quick($this->member)->assertStatus(201)->json('message.id');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/marvflow/messages/direct', ['body' => 'x', 'client_token' => (string) Str::uuid()])->assertUnauthorized();
        $this->getJson('/api/marvflow/messages?kind=direct')->assertUnauthorized();

        foreach ([$this->admin, $this->fin, $this->investor] as $u) $this->quick($u)->assertForbidden();

        foreach ([$this->member, $this->lead, $this->investor, $this->fin] as $u) {        // nobody but an admin reads the Admin inbox
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertForbidden();
        }
        // a quick message is not reachable through the Financial inbox either (it is addressed to the admin role)
        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertNotFound();
        $this->assertSame(1, MarvflowMessage::count());
    }

    public function test_a_member_cannot_read_another_members_quick_messages(): void
    {
        $theirs = $this->quick($this->lead)->assertStatus(201)->json('message.id');
        $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/messages/{$theirs}")->assertNotFound();
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertJsonCount(0, 'data');
    }

    // ── Existing messages survive the upgrade ─────────────────────────────────

    public function test_messages_saved_before_the_kind_column_existed_remain_readable_as_emails(): void
    {
        DB::table('marvflow_messages')->insert([                            // exactly the columns the ORIGINAL table had — no `kind`
            'sender_id' => $this->member->id, 'recipient_role' => 'admin', 'subject' => 'Old formal message', 'body_html' => '<p>Written last week</p>',
            'body_text' => 'Written last week', 'client_token' => (string) Str::uuid(), 'status' => 'sent', 'notified_count' => 1,
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);
        $id = (int) DB::table('marvflow_messages')->value('id');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk()
            ->assertJsonPath('message.kind', 'email')->assertJsonPath('message.subject', 'Old formal message');
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=email')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->member, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Professional email: the new formatting controls survive the full round trip ─

    public function test_the_extended_formatting_toolbar_output_is_saved_and_read_back_intact(): void
    {
        $html = '<p style="text-align: justify; line-height: 2; margin-bottom: 24px;"><span style="font-family: Georgia; font-size: 24px; color: rgb(230, 0, 0); background-color: #ffff00;">'
              . '<strong><em><u><s>All formats</s></u></em></strong></span> and <a href="https://ssi.example/policy?a=1" rel="noopener noreferrer" target="_blank">a link</a></p>'
              . '<p class="ql-indent-2">Indented</p><ol><li class="ql-indent-1">numbered</li></ol><ul><li>bulleted</li></ul>';

        $id = $this->actingAs($this->member, 'sanctum')->postJson('/api/marvflow/messages', [
            'recipient' => 'financial', 'subject' => 'Formatted – “checklist”', 'body' => $html, 'client_token' => (string) Str::uuid(),
        ])->assertStatus(201)->json('message.id');

        $res = $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.subject', 'Formatted – “checklist”');
        $body = $res->json('message.body_html');

        foreach (['text-align:justify', 'line-height:2', 'margin-bottom:24px', 'font-family:Georgia', 'font-size:24px', 'color:rgb(230, 0, 0)', 'background-color:#ffff00',
                  '<strong>', '<em>', '<u>', '<s>', 'href="https://ssi.example/policy?a=1"', 'rel="noopener noreferrer nofollow"', 'target="_blank"',
                  'class="ql-indent-2"', 'class="ql-indent-1"', '<ol>', '<ul>', 'All formats', 'a link'] as $kept) {
            $this->assertStringContainsString($kept, $body, "lost: {$kept}");
        }
    }

    public function test_hostile_links_and_styles_sent_through_the_email_endpoint_are_neutralised(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')->postJson('/api/marvflow/messages', [
            'recipient' => 'admin', 'subject' => 'Hostile', 'client_token' => (string) Str::uuid(),
            'body' => '<p><a href="javascript:alert(1)" onclick="x()">bad link</a> <a href="https://ok.example/" onmouseover="y()" style="position:fixed">ok link</a>'
                    . '<span style="color:expression(alert(1));background-color:url(javascript:1);font-family:evil">x</span></p>',
        ])->assertStatus(201)->json('message.id');

        $body = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->json('message.body_html');
        foreach (['javascript:', 'onclick', 'onmouseover', 'position', 'expression', 'url(', 'font-family'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $body);
        $this->assertSame(1, substr_count($body, '<a '));                  // only the safe link remains
        $this->assertStringContainsString('bad link', $body);
        $this->assertStringContainsString('href="https://ok.example/"', $body);
    }
}
