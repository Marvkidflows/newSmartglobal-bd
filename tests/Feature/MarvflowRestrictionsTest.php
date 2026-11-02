<?php
// LOCATION: tests/Feature/MarvflowRestrictionsTest.php
//
// Messaging-level restrictions and "pending delivery":
//  • only `active` accounts may list/read/send/reply on the MarvFlow messaging endpoints (MarvFlow side AND SSI inboxes);
//  • an Admin may reply to a conversation whose owner is restricted — stored PENDING DELIVERY after explicit confirmation,
//    never alerted, never visible/counted for the owner, released only once the owner is active again; Financial may not.
// Login and every other endpoint are deliberately NOT changed by this feature and are not asserted here.

namespace Tests\Feature;

use App\Http\Middleware\EnsureMessagingAccountActive;
use App\Models\MarvflowMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $other;
    protected User $admin;
    protected User $admin2;
    protected User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner  = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Mara Dev']);
        $this->other  = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Omar Other']);
        $this->admin  = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->admin2 = User::factory()->create(['role' => 'admin', 'name' => 'Abe Admin']);
        $this->fin    = User::factory()->create(['role' => 'financial', 'name' => 'Fay Fin']);
    }

    protected function tok(): string { return (string) Str::uuid(); }

    protected function alerts(User $u)
    {
        return $u->notifications()->where('data->type', 'marvflow_message')->get();
    }

    protected function start(User $as, string $to = 'admin', string $subject = 'Currency policy checklist'): int
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages', ['recipient' => $to, 'subject' => $subject, 'body' => '<p>Please review.</p>', 'client_token' => $this->tok()])
            ->assertStatus(201)->json('message.id');
    }

    protected function quickStart(User $as): int
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages/direct', ['body' => 'Quick question', 'client_token' => $this->tok()])->assertStatus(201)->json('message.id');
    }

    protected function ssiReply(User $as, string $inbox, int $id, array $over = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson("/api/{$inbox}/marvflow-messages/{$id}/reply", $over + ['body' => '<p>SECRET-PENDING-BODY</p>', 'client_token' => $this->tok()]);
    }

    protected function restrict(User $u, string $status = 'suspended'): void
    {
        $u->forceFill(['status' => $status])->save();
    }

    // ── 1. Only active accounts may use the MarvFlow messaging endpoints ──────

    public function test_restricted_marvflow_users_are_denied_on_every_messaging_endpoint_and_nothing_is_saved(): void
    {
        $id = $this->start($this->owner);
        $quick = $this->quickStart($this->owner);

        foreach (['suspended', 'frozen', 'deactivated'] as $status) {         // 'inactive' is exercised on the middleware directly (see below)
            $this->restrict($this->owner, $status);
            $before = MarvflowMessage::count();
            $as = fn () => $this->actingAs($this->owner->fresh(), 'sanctum');

            $calls = [
                $as()->getJson('/api/marvflow/messages/recipients'),
                $as()->getJson('/api/marvflow/messages'),
                $as()->getJson('/api/marvflow/messages?kind=direct'),
                $as()->getJson('/api/marvflow/messages/unread-count'),
                $as()->getJson("/api/marvflow/messages/{$id}"),
                $as()->postJson('/api/marvflow/messages', ['recipient' => 'admin', 'subject' => 'Sneaky subject', 'body' => '<p>x</p>', 'client_token' => $this->tok()]),
                $as()->postJson('/api/marvflow/messages/direct', ['body' => 'x', 'client_token' => $this->tok()]),
                $as()->postJson("/api/marvflow/messages/{$id}/reply", ['body' => '<p>x</p>', 'client_token' => $this->tok()]),
                $as()->postJson("/api/marvflow/messages/{$quick}/reply", ['body' => 'x', 'client_token' => $this->tok()]),
            ];
            foreach ($calls as $res) {
                $res->assertStatus(403)->assertJsonPath('code', 'account_restricted')->assertJsonPath('status', $status);
            }
            $this->assertSame($before, MarvflowMessage::count(), "status {$status} must not save anything");
        }

        $this->restrict($this->owner, 'active');                                // and an active account works again, with its history intact
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk();
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_middleware_treats_every_non_active_status_including_inactive_as_restricted(): void
    {
        $mw = new EnsureMessagingAccountActive();
        foreach (['suspended', 'inactive', 'frozen', 'deactivated'] as $status) {
            $user = new User(); $user->status = $status;
            $req  = Request::create('/api/marvflow/messages'); $req->setUserResolver(fn () => $user);
            $res  = $mw->handle($req, fn () => response('next'));
            $this->assertSame(403, $res->getStatusCode(), $status);
            $this->assertSame('account_restricted', json_decode($res->getContent(), true)['code']);
        }
        foreach (['active', null] as $status) {                                 // active (and the legacy empty status) pass
            $user = new User(); $user->status = $status;
            $req  = Request::create('/api/marvflow/messages'); $req->setUserResolver(fn () => $user);
            $this->assertSame('next', $mw->handle($req, fn () => response('next'))->getContent());
        }
    }

    public function test_restricted_ssi_staff_are_denied_on_the_inbox_endpoints_too(): void
    {
        $adminThread = $this->start($this->owner, 'admin');
        $finThread   = $this->start($this->owner, 'financial', 'Payout question');
        $before      = MarvflowMessage::count();

        $this->restrict($this->admin, 'suspended');
        $this->restrict($this->fin, 'frozen');

        foreach ([['admin', $this->admin, $adminThread], ['financial', $this->fin, $finThread], ['financial', $this->admin, $finThread]] as [$inbox, $u, $id]) {
            $as = fn () => $this->actingAs($u->fresh(), 'sanctum');
            $as()->getJson("/api/{$inbox}/marvflow-messages")->assertStatus(403)->assertJsonPath('code', 'account_restricted');
            $as()->getJson("/api/{$inbox}/marvflow-messages/unread-count")->assertStatus(403);
            $as()->getJson("/api/{$inbox}/marvflow-messages/{$id}")->assertStatus(403);
            $as()->postJson("/api/{$inbox}/marvflow-messages/{$id}/reply", ['body' => '<p>x</p>', 'client_token' => $this->tok()])->assertStatus(403);
        }
        $this->assertSame($before, MarvflowMessage::count());
        $this->assertNull(MarvflowMessage::find($adminThread)->read_at);        // a restricted account cannot even mark a message read
    }

    public function test_the_restriction_is_limited_to_the_messaging_endpoints(): void
    {
        $this->restrict($this->owner, 'suspended');
        $as = fn () => $this->actingAs($this->owner->fresh(), 'sanctum');

        $as()->getJson('/api/marvflow/messages')->assertStatus(403)->assertJsonPath('code', 'account_restricted');
        // other MarvFlow endpoints are NOT gated by this feature (whatever they answer, it is not our restriction response)
        foreach (['/api/marvflow/dashboard', '/api/marvflow/requests'] as $url) {
            $res = $as()->getJson($url);
            $this->assertNotSame('account_restricted', $res->json('code'), $url);
        }
    }

    // ── 2. Admin replies to a restricted owner: pending delivery ──────────────

    public function test_admin_must_confirm_and_the_reply_is_stored_pending_with_no_alert(): void
    {
        $id = $this->start($this->owner);
        $this->restrict($this->owner);

        // the staff view tells the UI what to warn about
        $view = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk();
        $view->assertJsonPath('reply.allowed', true)->assertJsonPath('reply.pending', true);
        $this->assertStringContainsString('PENDING DELIVERY', $view->json('reply.notice'));
        $this->assertStringNotContainsString('suspended', json_encode($view->json()));        // only "restricted": the exact status is not exposed

        // without confirmation: refused with a stable code, nothing saved
        $before = MarvflowMessage::count();
        $this->ssiReply($this->admin, 'admin', $id)->assertStatus(409)->assertJsonPath('code', 'owner_restricted');
        $this->assertSame($before, MarvflowMessage::count());

        // with confirmation: stored, flagged pending, owner NOT alerted
        $res = $this->ssiReply($this->admin, 'admin', $id, ['confirm_pending' => true])->assertStatus(201)->assertJsonPath('pending_delivery', true);
        $res->assertJsonPath('thread.1.pending_delivery', true)->assertJsonPath('thread.1.sender_name', 'Ada Admin');
        $row = MarvflowMessage::where('direction', 'to_marvflow')->firstOrFail();
        $this->assertNotNull($row->pending_since);
        $this->assertSame($this->owner->id, $row->recipient_id);
        $this->assertSame(0, $row->notified_count);
        $this->assertCount(0, $this->alerts($this->owner));
        foreach ($this->owner->notifications as $n) $this->assertStringNotContainsString('SECRET-PENDING-BODY', json_encode($n->data));

        // other staff see it, flagged, and it does not change SSI unread counts
        $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk()->assertJsonPath('thread.1.pending_delivery', true);
    }

    public function test_the_owner_cannot_see_or_count_pending_replies_until_active_and_then_gets_them_once(): void
    {
        $id = $this->start($this->owner);
        $this->restrict($this->owner);
        $this->ssiReply($this->admin, 'admin', $id, ['confirm_pending' => true])->assertStatus(201);
        $this->ssiReply($this->admin2, 'admin', $id, ['confirm_pending' => true, 'body' => '<p>second pending</p>'])->assertStatus(201);

        // while restricted: every endpoint is denied (nothing exposed)
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertStatus(403);
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertStatus(403);

        // another MarvFlow user never sees them either
        $this->actingAs($this->other, 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertNotFound();

        // reactivated: the first messaging request releases them and alerts ONCE (subject only, never the body)
        $this->restrict($this->owner, 'active');
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertOk()->assertJsonPath('unread_count', 2);
        $this->assertSame(0, MarvflowMessage::whereNotNull('pending_since')->count());
        $alerts = $this->alerts($this->owner);
        $this->assertCount(1, $alerts);
        $d = $alerts->first()->data;
        $this->assertSame($id, $d['thread_id']);
        $this->assertSame('/marvflow/messages', $d['link']);
        $this->assertStringContainsString('Re: Currency policy checklist', $d['message']);
        $this->assertStringNotContainsString('SECRET', json_encode($d));
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertOk();   // idempotent: no second alert
        $this->assertCount(1, $this->alerts($this->owner));

        $thread = $this->actingAs($this->owner->fresh(), 'sanctum')->getJson("/api/marvflow/messages/{$id}")->assertOk()->assertJsonCount(3, 'thread')->json('thread');
        $this->assertSame(['marvflow', 'ssi', 'ssi'], array_column($thread, 'from'));
        $this->assertSame([false, false, false], array_column($thread, 'pending_delivery'));
        $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 0);   // opened → read
    }

    public function test_pending_replies_in_a_quick_conversation_appear_in_the_chat_only_after_release(): void
    {
        $q = $this->quickStart($this->owner);
        $this->restrict($this->owner, 'frozen');
        $this->ssiReply($this->admin, 'admin', $q, ['body' => 'We have noted this', 'confirm_pending' => true])->assertStatus(201);
        $this->assertCount(0, $this->alerts($this->owner));

        $this->restrict($this->owner, 'active');
        $chat = $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk();
        $chat->assertJsonCount(2, 'data')->assertJsonPath('data.0.from', 'ssi');
        $this->assertCount(1, $this->alerts($this->owner));
        $this->assertStringNotContainsString('noted', json_encode($this->alerts($this->owner)->first()->data));
    }

    public function test_a_retried_pending_reply_is_not_duplicated(): void
    {
        $id = $this->start($this->owner);
        $this->restrict($this->owner);
        $tok = $this->tok();
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => $tok, 'confirm_pending' => true])->assertStatus(201);
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => $tok, 'confirm_pending' => true])->assertStatus(200)->assertJsonPath('duplicate', true);
        $this->ssiReply($this->admin, 'admin', $id, ['client_token' => $tok])->assertStatus(200)->assertJsonPath('duplicate', true);   // a retry needs no second confirmation
        $this->assertSame(1, MarvflowMessage::where('direction', 'to_marvflow')->count());
    }

    // ── 3. Financial Team may not reply to restricted-owner conversations ─────

    public function test_financial_cannot_reply_to_a_restricted_owners_conversation_but_admin_can_via_the_financial_inbox(): void
    {
        $id = $this->start($this->owner, 'financial', 'Payout question');
        $this->restrict($this->owner);

        $view = $this->actingAs($this->fin, 'sanctum')->getJson("/api/financial/marvflow-messages/{$id}")->assertOk();
        $view->assertJsonPath('reply.allowed', false)->assertJsonPath('reply.pending', false);
        $this->assertStringContainsString('Only an Admin', $view->json('reply.notice'));

        $before = MarvflowMessage::count();
        $this->ssiReply($this->fin, 'financial', $id, ['confirm_pending' => true])->assertStatus(403)->assertJsonPath('code', 'reply_not_permitted');
        $this->ssiReply($this->fin, 'financial', $id)->assertStatus(403);
        $this->assertSame($before, MarvflowMessage::count());

        $this->ssiReply($this->admin, 'financial', $id)->assertStatus(409);
        $this->ssiReply($this->admin, 'financial', $id, ['confirm_pending' => true])->assertStatus(201)->assertJsonPath('pending_delivery', true);
        $this->assertNotNull(MarvflowMessage::where('direction', 'to_marvflow')->first()->pending_since);
    }

    public function test_active_owners_are_unaffected_replies_are_delivered_immediately(): void
    {
        $id = $this->start($this->owner);
        $this->actingAs($this->fin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$id}")->assertOk()->assertJsonPath('reply.pending', false)->assertJsonPath('reply.notice', null);

        $this->ssiReply($this->admin, 'admin', $id, ['body' => '<p>fine</p>'])->assertStatus(201)->assertJsonPath('pending_delivery', false);
        $this->assertNull(MarvflowMessage::where('direction', 'to_marvflow')->first()->pending_since);
        $this->assertCount(1, $this->alerts($this->owner));
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/marvflow/messages/unread-count')->assertJsonPath('unread_count', 1);
        $this->assertSame(2, MarvflowMessage::count());
    }

    // ── Migration safety ──────────────────────────────────────────────────────

    public function test_the_pending_delivery_migration_refuses_to_roll_back_while_replies_are_pending(): void
    {
        $id = $this->start($this->owner);
        $this->restrict($this->owner);
        $this->ssiReply($this->admin, 'admin', $id, ['confirm_pending' => true])->assertStatus(201);

        $migration = require base_path('database/migrations/2026_10_29_100001_add_pending_delivery_to_marvflow_messages_table.php');
        try {
            $migration->down();
            $this->fail('down() must refuse while replies are pending delivery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('marvflow_messages', 'pending_since'));
        $this->assertSame(1, MarvflowMessage::whereNotNull('pending_since')->count());
    }

    public function test_existing_rows_get_no_pending_flag_from_the_migration(): void
    {
        $id = $this->start($this->owner);
        $this->assertNull(MarvflowMessage::find($id)->pending_since);
    }
}
