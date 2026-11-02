<?php
// LOCATION: tests/Feature/MarvflowRepliesTest.php
//
// Two-way MarvFlow ↔ SSI conversations: replies, history, ownership, role permissions, unread counts (both directions),
// alerts + click-through data, retries, and migration safety. Runs against the SQLite in-memory test database (phpunit.xml).

namespace Tests\Feature;

use App\Models\MarvflowMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowRepliesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;      // MarvFlow member who starts conversations
    protected User $other;      // another MarvFlow member (must never see the owner's conversations)
    protected User $lead;
    protected User $admin;
    protected User $admin2;
    protected User $fin;
    protected User $fin2;
    protected User $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner    = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Mara Dev']);
        $this->other    = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Omar Other']);
        $this->lead     = User::factory()->create(['role' => 'marvflow_lead', 'name' => 'Lee Lead']);
        $this->admin    = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->admin2   = User::factory()->create(['role' => 'admin', 'name' => 'Abe Admin']);
        $this->fin      = User::factory()->create(['role' => 'financial', 'name' => 'Fay Finance']);
        $this->fin2     = User::factory()->create(['role' => 'financial', 'name' => 'Finn Finance']);
        $this->investor = User::factory()->create(['role' => 'investor']);
    }

    protected function tok(): string { return (string) Str::uuid(); }

    protected function alerts(User $u)
    {
        return $u->notifications()->where('data->type', 'marvflow_message')->get();
    }

    /** MarvFlow first message; returns its id. */
    protected function start(User $as, string $recipient = 'admin', array $over = []): int
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages', $over + [
            'recipient' => $recipient, 'subject' => 'Currency policy checklist', 'body' => '<p>Please review the plan.</p>', 'client_token' => $this->tok(),
        ])->assertStatus(201)->json('message.id');
    }

    protected function quickStart(User $as): int
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages/direct', ['body' => 'Quick question', 'client_token' => $this->tok()])
            ->assertStatus(201)->json('message.id');
    }

    protected function ssiReply(User $as, string $inbox, int $id, array $over = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson("/api/{$inbox}/marvflow-messages/{$id}/reply", $over + ['body' => '<p>Thanks — approved.</p>', 'client_token' => $this->tok()]);
    }

    protected function mfReply(User $as, int $id, array $over = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson("/api/marvflow/messages/{$id}/reply", $over + ['body' => '<p>One more question.</p>', 'client_token' => $this->tok()]);
    }

    // ── SSI replies to MarvFlow ───────────────────────────────────────────────

    public function test_an_admin_reply_is_stored_in_the_same_conversation_and_addressed_to_the_owner_by_the_server(): void
    {
        $id  = $this->start($this->owner);
        $res = $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);

        $res->assertJsonPath('duplicate', false)->assertJsonCount(2, 'thread')
            ->assertJsonPath('thread.0.from', 'marvflow')->assertJsonPath('thread.1.from', 'ssi')->assertJsonPath('thread.1.sender_name', 'Ada Admin');

        $row = MarvflowMessage::where('direction', 'to_marvflow')->firstOrFail();
        $this->assertSame($id, $row->thread_id);
        $this->assertSame($this->admin->id, $row->sender_id);          // sender = the signed-in user
        $this->assertSame($this->owner->id, $row->recipient_id);       // recipient = the conversation owner, derived on the server
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame('email', $row->kind);
        $this->assertSame(2, MarvflowMessage::count());                // no parallel conversation was created
    }

    public function test_the_owner_sees_the_reply_in_the_conversation_and_can_answer_again(): void
    {
        $id = $this->start($this->owner);
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reply_count', 1)->assertJsonPath('data.0.unread_replies', 1)->assertJsonPath('unread_count', 1);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk()->assertJsonCount(2, 'thread');

        $res = $this->mfReply($this->owner, $id)->assertStatus(201);
        $res->assertJsonCount(3, 'thread')->assertJsonPath('thread.2.from', 'marvflow');
        $follow = MarvflowMessage::where('thread_id', $id)->where('direction', 'to_ssi')->firstOrFail();
        $this->assertSame($this->owner->id, $follow->sender_id);
        $this->assertSame('admin', $follow->recipient_role);

        // the admin sees ONE conversation (not three rows) with the follow-up unread
        $list = $this->actingAs($this->admin2, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.reply_count', 2)->assertJsonPath('data.0.is_read', false)->assertJsonPath('unread_count', 2);
    }

    // ── Ownership ─────────────────────────────────────────────────────────────

    public function test_another_marvflow_member_or_lead_cannot_read_or_reply_to_the_owners_conversation(): void
    {
        $id = $this->start($this->owner);
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);
        $replyId = MarvflowMessage::where('direction', 'to_marvflow')->value('id');

        foreach ([$this->other, $this->lead] as $intruder) {
            foreach ([$id, $replyId] as $target) {
                $this->actingAs($intruder, 'sanctum')->getJson("/api/marvflow/messages/{$target}")->assertNotFound();
                $this->mfReply($intruder, $target)->assertNotFound();
            }
            $this->actingAs($intruder, 'sanctum')->getJson('/api/marvflow/messages')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
            $this->actingAs($intruder, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertSame(0, MarvflowMessage::where('sender_id', $this->other->id)->count());
    }

    // ── Who may reply (server-side) ───────────────────────────────────────────

    public function test_role_permissions_for_replying(): void
    {
        $adminThread = $this->start($this->owner, 'admin');
        $finThread   = $this->start($this->owner, 'financial', ['subject' => 'Payout question']);

        // Admin conversation: admins only
        $this->ssiReply($this->fin, 'financial', $adminThread)->assertNotFound();      // wrong inbox → "not found", not a leak
        $this->ssiReply($this->fin, 'admin', $adminThread)->assertForbidden();         // financial cannot use the admin inbox
        $this->ssiReply($this->investor, 'admin', $adminThread)->assertForbidden();
        $this->ssiReply($this->owner, 'admin', $adminThread)->assertForbidden();       // MarvFlow cannot answer as SSI
        $this->ssiReply($this->admin, 'admin', $adminThread)->assertStatus(201);

        // Financial conversation: any financial user AND admins
        $this->ssiReply($this->fin2, 'financial', $finThread)->assertStatus(201);
        $this->ssiReply($this->admin, 'financial', $finThread)->assertStatus(201);
        $this->ssiReply($this->admin, 'admin', $finThread)->assertNotFound();         // addressed inbox is fixed by the conversation
        $this->ssiReply($this->investor, 'financial', $finThread)->assertForbidden();
        $this->ssiReply($this->other, 'financial', $finThread)->assertForbidden();

        $this->assertSame(3, MarvflowMessage::where('direction', 'to_marvflow')->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $id = $this->start($this->owner);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/admin/marvflow-messages/{$id}/reply", ['body' => 'x', 'client_token' => $this->tok()])->assertUnauthorized();
        $this->postJson("/api/marvflow/messages/{$id}/reply", ['body' => 'x', 'client_token' => $this->tok()])->assertUnauthorized();
        $this->getJson('/api/marvflow/messages/unread-count')->assertUnauthorized();
    }

    public function test_the_client_cannot_choose_the_thread_direction_recipient_sender_or_role_of_a_reply(): void
    {
        $id = $this->start($this->owner);
        $other = $this->start($this->other);

        $this->ssiReply($this->admin, 'admin', $id, [
            'recipient_id' => $this->other->id, 'sender_id' => $this->fin->id, 'thread_id' => $other, 'direction' => 'to_ssi',
            'recipient_role' => 'financial', 'recipient' => 'financial', 'kind' => 'direct', 'subject' => 'forged', 'role' => 'financial',
        ])->assertStatus(201);

        $row = MarvflowMessage::where('direction', 'to_marvflow')->firstOrFail();
        $this->assertSame($id, $row->thread_id);
        $this->assertSame($this->owner->id, $row->recipient_id);
        $this->assertSame($this->admin->id, $row->sender_id);
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame('email', $row->kind);
        $this->assertSame('Currency policy checklist', $row->subject);
        $this->assertSame(0, $this->alerts($this->other)->count());      // the other member was not alerted

        $this->mfReply($this->owner, $id, ['recipient' => 'financial', 'thread_id' => $other, 'direction' => 'to_marvflow', 'recipient_id' => $this->other->id])->assertStatus(201);
        $follow = MarvflowMessage::where('sender_id', $this->owner->id)->whereNotNull('thread_id')->firstOrFail();
        $this->assertSame($id, $follow->thread_id);
        $this->assertSame('to_ssi', $follow->direction);
        $this->assertNull($follow->recipient_id);
        $this->assertSame('admin', $follow->recipient_role);
    }

    // ── Unread counts and read status, both directions ────────────────────────

    public function test_unread_counts_and_read_status_for_initial_messages_and_replies(): void
    {
        $id = $this->start($this->owner);

        // initial message: unread for SSI, nothing for MarvFlow
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 1);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 0);

        // admin opens it → read, status recorded, count drops
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.is_read', true);
        $this->actingAs($this->admin2, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);

        // reply: unread for the owner only, and the SSI count is untouched by SSI's own reply
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 1);
        $this->actingAs($this->other, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 0);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);

        // owner opens → reply read (by the owner), count 0
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk()->assertJsonPath('thread.1.is_read', true)->assertJsonPath('thread.1.read_by', 'Mara Dev');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 0);

        // a MarvFlow follow-up makes the conversation unread for SSI again, and opening it clears that
        $this->mfReply($this->owner, $id)->assertStatus(201);
        $this->actingAs($this->admin2, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 1);
        $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);
    }

    public function test_an_admin_opening_a_financial_conversation_does_not_mark_it_read_and_a_financial_user_does(): void
    {
        $id = $this->start($this->owner, 'financial');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.is_read', false);
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/marvflow-messages/unread-count')->assertJsonPath('unread_count', 1);

        $this->ssiReply($this->admin, 'financial', $id)->assertStatus(201);                       // an admin reply is not a "read"
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/marvflow-messages/unread-count')->assertJsonPath('unread_count', 1);

        $this->actingAs($this->fin2, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk()->assertJsonPath('message.is_read', true);
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);
    }

    public function test_marking_read_is_authorised_server_side_and_limited_to_the_conversations_own_inbox(): void
    {
        $adminThread = $this->start($this->owner, 'admin');

        $this->actingAs($this->fin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$adminThread}")->assertForbidden();
        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$adminThread}")->assertNotFound();
        $this->actingAs($this->investor, 'sanctum')->getJson("/api/admin/marvflow-messages/{$adminThread}")->assertForbidden();
        $this->actingAs($this->other, 'sanctum')->getJson("/api/marvflow/messages/{$adminThread}")->assertNotFound();

        $this->assertNull(MarvflowMessage::find($adminThread)->read_at);                           // none of the above marked it read
        $this->assertSame(0, MarvflowMessage::whereNotNull('read_by')->count());
    }

    // ── Alerts, click-through data, de-duplication ────────────────────────────

    public function test_a_reply_alerts_only_the_owner_and_never_contains_the_body(): void
    {
        $id = $this->start($this->owner);
        $this->ssiReply($this->admin, 'admin', $id, ['body' => '<p>SECRET-REPLY-BODY</p>'])->assertStatus(201);

        $mine = $this->alerts($this->owner);
        $this->assertCount(1, $mine);
        $n = $mine->first()->data;
        $this->assertSame('/marvflow/messages', $n['link']);
        $this->assertSame($id, $n['thread_id']);
        $this->assertStringContainsString('Re: Currency policy checklist', $n['message']);
        $this->assertStringNotContainsString('SECRET-REPLY-BODY', json_encode($n));
        foreach ([$this->other, $this->lead, $this->fin] as $u) $this->assertCount(0, $this->alerts($u));          // nobody else is alerted
        $this->assertCount(1, $this->alerts($this->admin));                                         // (the admins only have the alert for the FIRST message)
        $this->assertCount(1, $this->alerts($this->admin2));
    }

    public function test_a_marvflow_follow_up_alerts_every_active_user_of_the_addressed_role_with_the_thread_id(): void
    {
        $id = $this->start($this->owner, 'financial');
        $this->fin2->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk();   // clears fin's alert
        $this->mfReply($this->owner, $id, ['body' => '<p>FOLLOW-UP-SECRET</p>'])->assertStatus(201);

        $this->assertCount(1, $this->alerts($this->fin)->filter(fn ($n) => ! $n->read_at));       // fresh alert after the first was cleared
        $n = $this->alerts($this->fin)->sortByDesc('created_at')->first()->data;
        $this->assertSame('/financial/marvflow-messages', $n['link']);
        $this->assertSame($id, $n['thread_id']);
        $this->assertStringNotContainsString('FOLLOW-UP-SECRET', json_encode($n));
        $this->assertSame(0, $this->alerts($this->admin)->count());                               // admins are not addressed
    }

    public function test_repeated_activity_does_not_pile_up_duplicate_unread_alerts_for_one_conversation(): void
    {
        $id = $this->start($this->owner);
        $this->assertCount(1, $this->alerts($this->admin));

        $this->mfReply($this->owner, $id)->assertStatus(201);
        $this->mfReply($this->owner, $id)->assertStatus(201);
        $this->assertCount(1, $this->alerts($this->admin));                                         // still ONE unread alert for this conversation

        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);
        $this->ssiReply($this->admin2, 'admin', $id)->assertStatus(201);
        $this->assertCount(1, $this->alerts($this->owner));                                         // one unread alert for the owner

        // opening the conversation clears the opener's alert; the next message alerts again
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk();
        $this->assertCount(0, $this->owner->fresh()->unreadNotifications()->where('data->type', 'marvflow_message')->get());
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);
        $this->assertCount(1, $this->owner->fresh()->unreadNotifications()->where('data->type', 'marvflow_message')->get());
    }

    public function test_a_retried_reply_is_not_duplicated_and_raises_no_second_alert(): void
    {
        $id  = $this->start($this->owner);
        $tok = $this->tok();
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => $tok])->assertStatus(201)->assertJsonPath('duplicate', false);
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => $tok])->assertStatus(200)->assertJsonPath('duplicate', true);
        $this->assertSame(1, MarvflowMessage::where('direction', 'to_marvflow')->count());
        $this->assertCount(1, $this->alerts($this->owner));

        $tok2 = $this->tok();
        $this->mfReply($this->owner, $id, ['client_token' => $tok2])->assertStatus(201);
        $this->mfReply($this->owner, $id, ['client_token' => $tok2])->assertStatus(200)->assertJsonPath('duplicate', true);
        $this->assertSame(1, MarvflowMessage::where('thread_id', $id)->where('direction', 'to_ssi')->count());
    }

    // ── Content rules ─────────────────────────────────────────────────────────

    public function test_reply_bodies_are_sanitised_validated_and_quick_conversations_stay_plain_text(): void
    {
        $id = $this->start($this->owner);
        $this->ssiReply($this->admin, 'admin', $id, ['body' => '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <strong>ok</strong></p>'])->assertStatus(201);
        $html = MarvflowMessage::where('direction', 'to_marvflow')->value('body_html');
        foreach (['<script', 'onclick', 'javascript:'] as $bad) $this->assertStringNotContainsString($bad, $html);
        $this->assertStringContainsString('<strong>ok</strong>', $html);

        $this->ssiReply($this->admin, 'admin', $id, ['body' => '<p> </p>'])->assertStatus(422);
        $this->ssiReply($this->admin, 'admin', $id, ['body' => ''])->assertStatus(422);
        $this->mfReply($this->owner, $id, ['body' => '<p><br></p>'])->assertStatus(422);
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => 'short'])->assertStatus(422);

        $q = $this->quickStart($this->owner);
        $this->ssiReply($this->admin, 'admin', $q, ['body' => '<b>bold</b> & <i>x</i>'])->assertStatus(201);
        $stored = MarvflowMessage::where('thread_id', $q)->where('direction', 'to_marvflow')->firstOrFail();
        $this->assertSame('direct', $stored->kind);
        $this->assertStringNotContainsString('<b>', $stored->body_html);                           // markup in a quick conversation is shown as text
        $this->assertStringContainsString('&lt;b&gt;', $stored->body_html);
        $this->ssiReply($this->admin, 'admin', $q, ['body' => str_repeat('a', 2001)])->assertStatus(422);
    }

    public function test_a_reply_to_a_conversation_whose_owner_has_no_marvflow_role_is_refused_and_nothing_is_saved(): void
    {
        $id = $this->start($this->owner);
        $this->owner->forceFill(['role' => 'investor'])->save();                 // e.g. the account was repurposed
        $before = MarvflowMessage::count();
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->assertSame($before, MarvflowMessage::count());
    }

    public function test_a_marvflow_follow_up_with_no_active_addressee_is_refused(): void
    {
        $id = $this->start($this->owner);
        User::where('role', 'admin')->update(['status' => 'suspended']);
        $before = MarvflowMessage::count();
        $this->mfReply($this->owner, $id)->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->assertSame($before, MarvflowMessage::count());
    }

    // ── Quick-message chat ────────────────────────────────────────────────────

    public function test_the_quick_message_chat_includes_the_owners_replies_and_marks_them_read(): void
    {
        $q = $this->quickStart($this->owner);
        $this->ssiReply($this->admin, 'admin', $q, ['body' => 'On it.'])->assertStatus(201);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 1);

        $chat = $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk();
        $chat->assertJsonCount(2, 'data')->assertJsonPath('data.0.from', 'ssi')->assertJsonPath('data.1.from', 'marvflow')->assertJsonPath('unread_count', 0);
        $this->assertNotNull(MarvflowMessage::where('direction', 'to_marvflow')->first()->read_at);

        $this->actingAs($this->other, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Existing behaviour is preserved ───────────────────────────────────────

    public function test_first_message_workflow_and_legacy_rows_still_work_exactly_as_before(): void
    {
        $legacy = DB::table('marvflow_messages')->insertGetId([
            'sender_id' => $this->owner->id, 'recipient_role' => 'admin', 'kind' => 'email', 'subject' => 'Written last week', 'body_html' => '<p>Old</p>',
            'body_text' => 'Old', 'client_token' => $this->tok(), 'status' => 'sent', 'notified_count' => 1, 'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);
        $m = MarvflowMessage::find($legacy);
        $this->assertNull($m->thread_id);
        $this->assertSame('to_ssi', $m->fresh()->direction);                                        // default applied to existing rows

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reply_count', 0)->assertJsonPath('data.0.is_read', false)->assertJsonPath('unread_count', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$legacy}")->assertOk()->assertJsonPath('message.body_html', '<p>Old</p>')->assertJsonCount(1, 'thread');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/marvflow/messages/{$legacy}")->assertOk()->assertJsonPath('message.status', 'read');

        // and a legacy message can be answered
        $this->ssiReply($this->admin, 'admin', $legacy)->assertStatus(201);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages')->assertJsonPath('data.0.reply_count', 1);
    }

    public function test_the_existing_staff_channel_and_investor_messages_are_untouched(): void
    {
        $this->start($this->owner);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/staff-messages')->assertOk();
        $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/staff-messages')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => 'Hello finance'])->assertStatus(201);
        $this->assertCount(0, $this->alerts($this->fin));                                           // staff-channel alerts are a different notification type
    }

    // ── Migration safety ──────────────────────────────────────────────────────

    public function test_the_threading_migration_refuses_to_roll_back_while_ssi_replies_exist_and_changes_nothing(): void
    {
        $id = $this->start($this->owner);
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(201);

        $migration = require base_path('database/migrations/2026_10_28_100001_add_threading_to_marvflow_messages_table.php');
        try {
            $migration->down();
            $this->fail('down() must refuse while SSI replies exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('marvflow_messages', ['thread_id', 'direction', 'recipient_id']));
        $this->assertSame(1, MarvflowMessage::where('direction', 'to_marvflow')->count());
    }
}
