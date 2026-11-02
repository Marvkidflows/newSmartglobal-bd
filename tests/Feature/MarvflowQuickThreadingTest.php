<?php
// LOCATION: tests/Feature/MarvflowQuickThreadingTest.php
//
// Quick messages ("Message Admin"): consecutive messages from one MarvFlow user to one SSI role stay in ONE running conversation —
// before and after SSI replies — chosen by the SERVER. A new conversation only starts on request. Runs on SQLite in memory.

namespace Tests\Feature;

use App\Models\MarvflowMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowQuickThreadingTest extends TestCase
{
    use RefreshDatabase;

    protected User $a;       // MarvFlow member
    protected User $b;       // another member
    protected User $admin;
    protected User $admin2;
    protected User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a      = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Ann A']);
        $this->b      = User::factory()->create(['role' => 'marvflow_lead', 'name' => 'Ben B']);
        $this->admin  = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->admin2 = User::factory()->create(['role' => 'admin', 'name' => 'Abe Admin']);
        $this->fin    = User::factory()->create(['role' => 'financial', 'name' => 'Fay Fin']);
    }

    protected function quick(User $as, string $body = 'Quick note', array $over = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/marvflow/messages/direct', $over + ['body' => $body, 'client_token' => (string) Str::uuid()]);
    }

    protected function alerts(User $u)
    {
        return $u->notifications()->where('data->type', 'marvflow_message')->get();
    }

    public function test_consecutive_quick_messages_share_one_conversation_before_any_reply(): void
    {
        $first  = $this->quick($this->a, 'one')->assertStatus(201)->assertJsonPath('new_conversation', true);
        $second = $this->quick($this->a, 'two')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $third  = $this->quick($this->a, 'three')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $root   = $first->json('message.id');

        $this->assertSame($root, $second->json('message.thread_id'));
        $this->assertSame($root, $third->json('message.thread_id'));
        $this->assertSame(3, MarvflowMessage::count());
        $this->assertSame(1, MarvflowMessage::whereNull('thread_id')->count());

        foreach (MarvflowMessage::whereNotNull('thread_id')->get() as $m) {
            $this->assertSame($root, $m->thread_id);
            $this->assertSame('to_ssi', $m->direction);
            $this->assertSame('direct', $m->kind);
            $this->assertSame('admin', $m->recipient_role);
            $this->assertSame($this->a->id, $m->sender_id);
            $this->assertNull($m->recipient_id);
        }

        // the admin sees ONE conversation holding all three messages, in order
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.reply_count', 2)->assertJsonPath('data.0.unread_count', 3)->assertJsonPath('unread_count', 3)->assertJsonPath('unread_by_kind.direct', 3);
        $open = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$root}")->assertOk()->assertJsonCount(3, 'thread');
        $this->assertSame(['one', 'two', 'three'], collect($open->json('thread'))->map(fn ($m) => trim(strip_tags($m['body_html'])))->all());
    }

    public function test_after_ssi_replies_every_further_message_continues_the_same_conversation(): void
    {
        $root = $this->quick($this->a, 'one')->assertStatus(201)->json('message.id');
        $this->quick($this->a, 'two')->assertStatus(201);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/marvflow-messages/{$root}/reply", ['body' => 'On it', 'client_token' => (string) Str::uuid()])->assertStatus(201);

        $after1 = $this->quick($this->a, 'three')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $after2 = $this->quick($this->a, 'four')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $this->assertSame($root, $after1->json('message.thread_id'));
        $this->assertSame($root, $after2->json('message.thread_id'));
        $this->assertSame(1, MarvflowMessage::whereNull('thread_id')->count());
        $this->assertSame(5, MarvflowMessage::count());

        // ordering is by id, oldest first, on both sides
        $thread = $this->actingAs($this->a, 'sanctum')->getJson("/api/marvflow/messages/{$root}")->assertOk()->assertJsonCount(5, 'thread')->json('thread');
        $this->assertSame(['marvflow', 'marvflow', 'ssi', 'marvflow', 'marvflow'], array_column($thread, 'from'));
        $this->assertSame(collect($thread)->pluck('id')->sort()->values()->all(), collect($thread)->pluck('id')->all());

        $chat = $this->actingAs($this->a, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(collect($chat->json('data'))->pluck('id')->sortDesc()->values()->all(), collect($chat->json('data'))->pluck('id')->all());   // newest first
        $this->assertSame([$root], collect($chat->json('data'))->pluck('thread_id')->unique()->values()->all());
    }

    public function test_unread_counts_and_read_status_follow_the_conversation(): void
    {
        $root = $this->quick($this->a, 'one')->json('message.id');
        $this->quick($this->a, 'two');
        $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/marvflow-messages/{$root}")->assertOk()->assertJsonPath('message.is_read', true);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);

        $this->quick($this->a, 'three')->assertStatus(201);                                         // a new message in an already-read conversation
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_read', false)->assertJsonPath('data.0.unread_count', 1);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$root}")->assertOk();
        $this->actingAs($this->admin2, 'sanctum')->getJson('/api/admin/marvflow-messages/unread-count')->assertJsonPath('unread_count', 0);

        // the sender's own view: every message in the conversation now shows as read, by an admin
        $this->assertSame(0, MarvflowMessage::where('direction', 'to_ssi')->whereNull('read_at')->count());
        $chat = $this->actingAs($this->a, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(3, 'data');
        $this->assertTrue(collect($chat->json('data'))->every(fn ($m) => $m['status'] === 'read'));
    }

    public function test_alerts_are_collapsed_per_conversation_and_carry_the_conversation_id_but_never_the_text(): void
    {
        $root = $this->quick($this->a, 'SECRET-ONE')->json('message.id');
        $this->quick($this->a, 'SECRET-TWO');
        $this->quick($this->a, 'SECRET-THREE');

        foreach ([$this->admin, $this->admin2] as $adm) {
            $this->assertCount(1, $this->alerts($adm));                                             // one unread alert for the whole conversation
            $n = $this->alerts($adm)->first()->data;
            $this->assertSame($root, $n['thread_id']);
            $this->assertSame('/admin/marvflow-messages', $n['link']);
            $this->assertStringNotContainsString('SECRET', json_encode($n));
        }
        $this->assertCount(0, $this->alerts($this->fin));

        // once an admin has opened the conversation, the next message alerts that admin again
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/marvflow-messages/{$root}")->assertOk();
        $this->quick($this->a, 'SECRET-FOUR')->assertStatus(201);
        $this->assertCount(1, $this->admin->fresh()->unreadNotifications()->where('data->type', 'marvflow_message')->get());
        $row = MarvflowMessage::where('body_text', 'SECRET-FOUR')->firstOrFail();
        $this->assertSame(1, $row->notified_count);                                                 // only the admin who had cleared theirs (admin2 still has an unread alert)
    }

    public function test_a_retried_follow_up_is_not_duplicated_and_raises_no_second_alert(): void
    {
        $this->quick($this->a, 'first')->assertStatus(201);
        $tok = (string) Str::uuid();
        $one = $this->quick($this->a, 'follow', ['client_token' => $tok])->assertStatus(201);
        $two = $this->quick($this->a, 'follow', ['client_token' => $tok])->assertStatus(200)->assertJsonPath('duplicate', true);

        $this->assertSame($one->json('message.id'), $two->json('message.id'));
        $this->assertSame(2, MarvflowMessage::count());
        $this->assertCount(1, $this->alerts($this->admin));

        // the very first message too
        $tok2 = (string) Str::uuid();
        $this->quick($this->b, 'hello', ['client_token' => $tok2])->assertStatus(201);
        $this->quick($this->b, 'hello', ['client_token' => $tok2])->assertStatus(200)->assertJsonPath('duplicate', true);
        $this->assertSame(1, MarvflowMessage::where('sender_id', $this->b->id)->count());
    }

    public function test_a_new_conversation_starts_only_on_request_and_then_becomes_the_open_one(): void
    {
        $old = $this->quick($this->a, 'old one')->json('message.id');
        $this->quick($this->a, 'old two');

        $new = $this->quick($this->a, 'fresh start', ['new_conversation' => true])->assertStatus(201)->assertJsonPath('new_conversation', true);
        $newRoot = $new->json('message.id');
        $this->assertNotSame($old, $newRoot);
        $this->assertSame($newRoot, $new->json('message.thread_id'));

        $next = $this->quick($this->a, 'continues the new one')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $this->assertSame($newRoot, $next->json('message.thread_id'));

        $this->assertSame(1, MarvflowMessage::where('thread_id', $old)->count());
        $this->assertSame(1, MarvflowMessage::where('thread_id', $newRoot)->count());
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/marvflow-messages')->assertOk()->assertJsonCount(2, 'data');

        $this->quick($this->a, 'x', ['new_conversation' => 'banana'])->assertStatus(422)->assertJsonValidationErrors('new_conversation');
    }

    public function test_threading_is_per_user_per_kind_and_per_role_and_the_client_cannot_pick_a_thread(): void
    {
        // user B has an open quick conversation; user A's message must never attach to it
        $bRoot = $this->quick($this->b, 'b says hi')->json('message.id');
        $aRoot = $this->quick($this->a, 'a says hi')->assertStatus(201)->json('message.id');
        $this->assertNotSame($bRoot, $aRoot);
        $this->assertSame(0, MarvflowMessage::where('thread_id', $bRoot)->count());

        // A's EMAIL conversation to the admin is never used for quick messages, and vice versa
        $emailRoot = $this->actingAs($this->a, 'sanctum')->postJson('/api/marvflow/messages', ['recipient' => 'admin', 'subject' => 'Formal subject', 'body' => '<p>Formal</p>', 'client_token' => (string) Str::uuid()])->assertStatus(201)->json('message.id');
        $q = $this->quick($this->a, 'quick again')->assertStatus(201);
        $this->assertSame($aRoot, $q->json('message.thread_id'));
        $this->assertSame(0, MarvflowMessage::where('thread_id', $emailRoot)->count());

        // a quick conversation addressed to a DIFFERENT role (e.g. an older row) is never picked up as the admin one
        $finRoot = DB::table('marvflow_messages')->insertGetId([
            'sender_id' => $this->a->id, 'recipient_role' => 'financial', 'kind' => 'direct', 'subject' => '', 'body_html' => '<p>old</p>', 'body_text' => 'old',
            'client_token' => (string) Str::uuid(), 'status' => 'sent', 'notified_count' => 0, 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute(),
        ]);
        $r = $this->quick($this->a, 'to admin', ['recipient' => 'financial', 'recipient_role' => 'financial', 'thread_id' => $finRoot, 'direction' => 'to_marvflow', 'recipient_id' => $this->b->id, 'sender_id' => $this->b->id, 'kind' => 'email', 'subject' => 'forged'])->assertStatus(201);
        $this->assertSame($aRoot, $r->json('message.thread_id'));
        $this->assertSame(0, MarvflowMessage::where('thread_id', $finRoot)->count());
        $row = MarvflowMessage::find($r->json('message.id'));
        $this->assertSame('admin', $row->recipient_role);
        $this->assertSame($this->a->id, $row->sender_id);
        $this->assertSame('direct', $row->kind);
        $this->assertSame('to_ssi', $row->direction);
        $this->assertNull($row->recipient_id);
        $this->assertSame(0, $this->alerts($this->fin)->count());
    }

    public function test_legacy_single_message_quick_rows_are_still_readable_and_the_latest_becomes_the_open_conversation(): void
    {
        $ids = [];
        foreach (['older', 'latest'] as $i => $text) {                                              // pre-upgrade rows: one root per message, no thread columns set
            $ids[] = DB::table('marvflow_messages')->insertGetId([
                'sender_id' => $this->a->id, 'recipient_role' => 'admin', 'kind' => 'direct', 'subject' => '', 'body_html' => "<p>{$text}</p>", 'body_text' => $text,
                'client_token' => (string) Str::uuid(), 'status' => 'sent', 'notified_count' => 1, 'created_at' => now()->subDays(2 - $i), 'updated_at' => now()->subDays(2 - $i),
            ]);
        }
        $this->actingAs($this->a, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(2, 'data');

        $new = $this->quick($this->a, 'after the upgrade')->assertStatus(201)->assertJsonPath('new_conversation', false);
        $this->assertSame($ids[1], $new->json('message.thread_id'));                                // attaches to the most recent one; nothing is rewritten
        $this->assertNull(DB::table('marvflow_messages')->where('id', $ids[0])->value('thread_id'));
        $this->assertNull(DB::table('marvflow_messages')->where('id', $ids[1])->value('thread_id'));
    }

    public function test_authorization_still_applies_to_follow_ups_and_nothing_is_saved_when_no_admin_is_active(): void
    {
        $root = $this->quick($this->a, 'one')->json('message.id');

        // other members cannot see the conversation; investors/SSI staff cannot send quick messages as MarvFlow
        $this->actingAs($this->b, 'sanctum')->getJson("/api/marvflow/messages/{$root}")->assertNotFound();
        $this->actingAs($this->b, 'sanctum')->getJson('/api/marvflow/messages?kind=direct')->assertOk()->assertJsonCount(0, 'data');
        $this->quick($this->admin)->assertForbidden();
        $this->quick($this->fin)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/marvflow/messages/direct', ['body' => 'x', 'client_token' => (string) Str::uuid()])->assertUnauthorized();

        // with an open conversation but no active admin, the follow-up is refused and nothing is saved
        User::where('role', 'admin')->update(['status' => 'suspended']);
        $before = MarvflowMessage::count();
        $this->quick($this->a, 'two')->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->assertSame($before, MarvflowMessage::count());
    }

    public function test_follow_up_validation_rules_match_first_messages(): void
    {
        $this->quick($this->a, 'one')->assertStatus(201);
        $before = MarvflowMessage::count();
        $this->quick($this->a, "   \n ")->assertStatus(422)->assertJsonValidationErrors('body');
        $this->quick($this->a, str_repeat('x', 2001))->assertStatus(422)->assertJsonValidationErrors('body');
        $this->quick($this->a, 'ok', ['client_token' => 'short'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->assertSame($before, MarvflowMessage::count());

        $m = $this->quick($this->a, '<script>alert(1)</script> <b>x</b>')->assertStatus(201);
        $this->assertStringNotContainsString('<script', $m->json('message.body_html'));
        $this->assertStringNotContainsString('<b>', $m->json('message.body_html'));
    }
}
