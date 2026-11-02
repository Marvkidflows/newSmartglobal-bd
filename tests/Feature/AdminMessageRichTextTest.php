<?php
// LOCATION: tests/Feature/AdminMessageRichTextTest.php
//
// Admin ↔ Investor messaging (the `messages` table / Admin Message Center) with the rich-text upgrade: formatted messages are stored as
// plain `body` + sanitised `body_html`; the legacy plain-text request still works; hostile HTML never reaches either side; investors
// (untrusted authors) are throttled; permissions, unread flags and notifications are unchanged. Runs on SQLite in memory.

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMessageRichTextTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $investor;
    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin     = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->financial = User::factory()->create(['role' => 'financial', 'name' => 'Fin Officer']);
        $this->investor  = $this->makeInvestor('Ivy Investor');
        $this->other     = $this->makeInvestor('Otto Other');
    }

    protected function makeInvestor(string $name): User
    {
        return User::factory()->create(['role' => 'investor', 'name' => $name, 'registration_stage' => 4, 'registration_completed' => true, 'balance' => 1000]);
    }

    protected function adm()     { return $this->actingAs($this->admin, 'sanctum'); }
    protected function inv()     { return $this->actingAs($this->investor, 'sanctum'); }
    protected function sendUrl() { return "/api/admin/messages/{$this->investor->id}/send"; }

    protected const HOSTILE = '<p>Hello <strong>friend</strong></p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>';

    public function test_admin_sends_a_formatted_message_stored_as_plain_text_plus_sanitised_html(): void
    {
        $res = $this->adm()->postJson($this->sendUrl(), ['subject' => 'Welcome', 'body_html' => '<p style="color:#ff0000;font-size:18px">Hello <strong>friend</strong></p><ul><li>one</li></ul>'])
            ->assertSuccessful();

        $row = Message::firstOrFail();
        $this->assertStringContainsString('Hello friend', $row->body);
        $this->assertStringNotContainsString('<', $row->body);                      // `body` is always PLAIN text
        $this->assertStringContainsString('<strong>friend</strong>', $row->body_html);
        $this->assertStringContainsString('color:#ff0000', $row->body_html);
        $this->assertSame('admin', $row->initiated_by);
        $this->assertSame($this->investor->id, $row->investor_id);
        $this->assertSame($this->admin->id, $row->sender_id);
        $this->assertStringContainsString('<strong>friend</strong>', $res->json('data.body_html'));

        // the investor sees both versions, in the same thread
        $thread = $this->inv()->getJson('/api/investor-investment/messages')->assertOk()->json('thread');
        $this->assertCount(1, $thread);
        $this->assertStringContainsString('<strong>friend</strong>', $thread[0]['body_html']);
        $this->assertStringContainsString('Hello friend', $thread[0]['body']);
    }

    public function test_the_legacy_plain_text_request_still_works_and_leaves_body_html_empty(): void
    {
        $this->adm()->postJson($this->sendUrl(), ['subject' => 'Plain', 'body' => "Line one\nLine two"])->assertSuccessful();

        $row = Message::firstOrFail();
        $this->assertSame("Line one\nLine two", $row->body);
        $this->assertNull($row->body_html);

        $thread = $this->inv()->getJson('/api/investor-investment/messages')->assertOk()->json('thread');
        $this->assertNull($thread[0]['body_html']);
        $this->assertSame("Line one\nLine two", $thread[0]['body']);
    }

    public function test_the_investor_replies_with_formatting_and_the_admin_sees_it_with_unchanged_unread_flags(): void
    {
        $this->adm()->postJson($this->sendUrl(), ['body' => 'How can we help?'])->assertSuccessful();

        $this->inv()->postJson('/api/investor-investment/messages', ['subject' => 'Question', 'body_html' => '<p>I have <em>one</em> question.</p>', 'department' => 'admin'])
            ->assertStatus(201);

        $row = Message::where('initiated_by', 'investor')->firstOrFail();
        $this->assertSame('I have one question.', $row->body);
        $this->assertStringContainsString('<em>one</em>', $row->body_html);
        $this->assertFalse((bool) $row->read_by_admin);                       // unread for admin, exactly as before
        $this->assertSame($this->investor->id, $row->investor_id);

        $thread = $this->adm()->getJson("/api/admin/messages/{$this->investor->id}")->assertOk()->json('thread');
        $this->assertCount(2, $thread);                                      // whole conversation history in one thread
        $this->assertStringContainsString('<em>one</em>', collect($thread)->firstWhere('from', 'investor')['body_html']);
        $this->assertNull(collect($thread)->firstWhere('from', 'admin')['body_html']);
    }

    public function test_hostile_html_is_sanitised_on_write_and_again_on_read_for_both_sides(): void
    {
        $this->adm()->postJson($this->sendUrl(), ['body_html' => self::HOSTILE])->assertSuccessful();
        $stored = Message::firstOrFail();
        foreach (['<script', 'onerror', 'javascript:', '<img'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $stored->body_html);
        $this->assertStringContainsString('<strong>friend</strong>', $stored->body_html);

        // a row that was written by something else (old code, direct SQL) is still cleaned when it is READ
        $raw = Message::create(['investor_id' => $this->investor->id, 'sender_id' => $this->admin->id, 'receiver_id' => $this->investor->id, 'body' => 'raw row', 'body_html' => self::HOSTILE,
                                'initiated_by' => 'admin', 'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);
        $this->assertStringContainsString('<script', Message::find($raw->id)->body_html);           // proves the stored row IS hostile

        foreach ([$this->adm()->getJson("/api/admin/messages/{$this->investor->id}"), $this->inv()->getJson('/api/investor-investment/messages')] as $res) {
            $json = json_encode($res->assertOk()->json('thread'));
            foreach (['<script', 'onerror', 'javascript:'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $json);
        }
    }

    public function test_the_derived_plain_text_is_searchable_and_free_of_non_breaking_spaces(): void
    {
        $this->adm()->postJson($this->sendUrl(), ['body_html' => "<p>hello&nbsp;world and&nbsp;&nbsp;more</p><p>second\u{200B} line</p>"])->assertSuccessful();
        $row = Message::firstOrFail();

        $this->assertStringNotContainsString("\u{00A0}", $row->body);
        $this->assertStringNotContainsString("\u{200B}", $row->body);
        $this->assertSame("hello world and more\nsecond line", $row->body);
        $this->assertSame(1, Message::where('body', 'like', '%hello world%')->count());             // the legacy `LIKE` search finds it
        $this->assertSame('hello world and more', collect(explode("\n", $row->body))->first());     // previews/notifications use this column
    }

    public function test_a_legacy_row_without_html_still_displays_as_plain_text(): void
    {
        Message::create(['investor_id' => $this->investor->id, 'sender_id' => $this->admin->id, 'receiver_id' => $this->investor->id, 'subject' => 'Old', 'body' => 'An old plain message <b>not html</b>', 'initiated_by' => 'admin',
                         'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);

        $t = $this->inv()->getJson('/api/investor-investment/messages')->assertOk()->json('thread.0');
        $this->assertNull($t['body_html']);
        $this->assertSame('An old plain message <b>not html</b>', $t['body']);                // returned as data; the UI renders it as TEXT
    }

    public function test_validation_empty_markup_only_oversize_and_legacy_limits(): void
    {
        $this->adm()->postJson($this->sendUrl(), [])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->adm()->postJson($this->sendUrl(), ['body_html' => '<p><br></p>'])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->adm()->postJson($this->sendUrl(), ['body_html' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->adm()->postJson($this->sendUrl(), ['body' => str_repeat('a', 5001)])->assertStatus(422);                         // existing 5,000-character limit
        $this->adm()->postJson($this->sendUrl(), ['body_html' => '<p>' . str_repeat('a', 5001) . '</p>'])->assertStatus(422);   // applies to the plain text of formatted messages too
        $this->adm()->postJson($this->sendUrl(), ['body_html' => '<p>' . str_repeat('a', 40001) . '</p>'])->assertStatus(422);  // 40,000-character HTML limit
        $this->adm()->postJson($this->sendUrl(), ['body' => str_repeat('a', 5000)])->assertSuccessful();                         // the boundary itself is accepted
        $this->assertSame(1, Message::count());
    }

    public function test_the_client_cannot_forge_sender_investor_direction_or_read_flags(): void
    {
        $this->inv()->postJson('/api/investor-investment/messages', [
            'body_html' => '<p>hi</p>', 'department' => 'admin', 'investor_id' => $this->other->id, 'sender_id' => $this->admin->id,
            'initiated_by' => 'admin', 'read_by_admin' => true, 'sender_label' => 'Totally Official',
        ])->assertStatus(201);

        $row = Message::firstOrFail();
        $this->assertSame($this->investor->id, $row->investor_id);
        $this->assertSame($this->investor->id, $row->sender_id);
        $this->assertSame('investor', $row->initiated_by);
        $this->assertFalse((bool) $row->read_by_admin);
        $this->assertNotSame('Totally Official', $row->sender_label);
    }

    public function test_permissions_are_unchanged(): void
    {
        $this->inv()->postJson($this->sendUrl(), ['body' => 'x'])->assertForbidden();                       // investors cannot use the admin endpoint
        $this->actingAs($this->financial, 'sanctum')->postJson($this->sendUrl(), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->financial, 'sanctum')->getJson("/api/admin/messages/{$this->investor->id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->sendUrl(), ['body' => 'x'])->assertUnauthorized();
        $this->postJson('/api/investor-investment/messages', ['body' => 'x'])->assertUnauthorized();
        $this->assertSame(0, Message::count());
    }

    public function test_an_investor_message_to_the_financial_team_alerts_with_plain_text_only(): void
    {
        $this->inv()->postJson('/api/investor-investment/messages', ['subject' => '', 'body_html' => '<p>Please <strong>check</strong> <script>alert(1)</script>my deposit</p>', 'department' => 'financial'])
            ->assertStatus(201);

        $row = Message::firstOrFail();
        $this->assertSame('financial', $row->department);
        $this->assertFalse((bool) $row->read_by_financial);
        $alert = json_encode($this->financial->notifications()->get()->pluck('data'));
        $this->assertStringContainsString('Please check', $alert);
        foreach (['<p>', '<strong>', '<script', 'alert(1)'] as $bad) $this->assertStringNotContainsString($bad, $alert);
    }

    public function test_investor_posting_is_throttled_to_sixty_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->inv()->postJson('/api/investor-investment/messages', ['body' => "m{$i}", 'department' => 'admin'])->assertStatus(201);
        }
        $this->inv()->postJson('/api/investor-investment/messages', ['body' => 'one too many', 'department' => 'admin'])->assertStatus(429);
        $this->assertSame(60, Message::count());
    }

    public function test_the_throttles_use_their_own_named_buckets(): void
    {
        $mw = function (string $method, string $uri) {
            $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === $uri);
            $this->assertNotNull($route, "{$method} {$uri} not found");
            return collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->values()->all();
        };
        $this->assertContains('throttle:60,1,investor-messages', $mw('POST', 'api/investor-investment/messages'));

        $prefixes = [
            $mw('POST', 'api/marvflow/messages')[0], $mw('POST', 'api/marvflow/messages/direct')[0], $mw('POST', 'api/marvflow/messages/{id}/reply')[0],
            $mw('POST', 'api/admin/marvflow-messages/{id}/reply')[0], $mw('POST', 'api/financial/marvflow-messages/{id}/reply')[0], 'throttle:60,1,investor-messages',
        ];
        $this->assertCount(count($prefixes), array_unique($prefixes), 'every messaging throttle must have its own bucket');
        foreach ($prefixes as $p) $this->assertCount(3, explode(',', $p), "{$p} has no prefix");
    }

    public function test_the_existing_admin_flow_and_marvflow_messages_are_untouched(): void
    {
        $this->adm()->postJson($this->sendUrl(), ['subject' => 'Hi', 'body' => 'Plain as always'])->assertSuccessful();
        $this->adm()->getJson('/api/admin/messages')->assertOk();
        $this->assertSame(0, \App\Models\MarvflowMessage::count());                                    // separate system, separate table
    }
}
