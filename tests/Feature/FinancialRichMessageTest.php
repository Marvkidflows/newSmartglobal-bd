<?php
// LOCATION: tests/Feature/FinancialRichMessageTest.php
//
// Financial ↔ Investor messaging (incl. broadcasts and the "also email" copy) with the rich-text upgrade. Existing behaviour — department
// identity, gates, audit entries, broadcast_id grouping, notices — is asserted unchanged alongside the new formatted path.

namespace Tests\Feature;

use App\Models\FinancialAuditLog;
use App\Models\Message;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FinancialRichMessageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $investor;
    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin     = User::factory()->create(['role' => 'admin']);
        $this->financial = User::factory()->create(['role' => 'financial', 'name' => 'Fin Officer']);
        $this->investor  = User::factory()->create(['role' => 'investor', 'name' => 'Ivy Investor', 'registration_stage' => 4, 'registration_completed' => true, 'balance' => 1000]);
        $this->other     = User::factory()->create(['role' => 'investor', 'name' => 'Otto Other', 'registration_stage' => 4, 'registration_completed' => true, 'balance' => 1000]);
    }

    protected function fin() { return $this->actingAs($this->financial, 'sanctum'); }
    protected function sendUrl(?User $to = null) { return '/api/financial/messages/' . ($to ?? $this->investor)->id . '/send'; }

    public function test_a_formatted_reply_keeps_department_identity_and_stores_plain_plus_html(): void
    {
        $this->fin()->postJson($this->sendUrl(), ['subject' => 'Your withdrawal', 'body_html' => '<p>It has been <strong>processed</strong>.</p>'])
            ->assertStatus(201)->assertJsonPath('data.sender', 'Smart System Investment — Financial Team');

        $row = Message::firstOrFail();
        $this->assertSame('financial', $row->department);
        $this->assertSame('Smart System Investment — Financial Team', $row->sender_label);
        $this->assertSame($this->financial->id, $row->sender_id);
        $this->assertSame('It has been processed.', $row->body);
        $this->assertStringContainsString('<strong>processed</strong>', $row->body_html);
        $this->assertFalse((bool) $row->read_by_investor);

        $thread = $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/messages')->assertOk()->json('thread');
        $this->assertStringContainsString('<strong>processed</strong>', $thread[0]['body_html']);
        $this->assertSame('financial', $thread[0]['department']);
    }

    public function test_the_legacy_plain_reply_is_unchanged(): void
    {
        $this->fin()->postJson($this->sendUrl(), ['body' => 'Within 24 hours.'])->assertStatus(201);
        $row = Message::firstOrFail();
        $this->assertSame('Within 24 hours.', $row->body);
        $this->assertNull($row->body_html);
    }

    public function test_investor_to_financial_roundtrip_with_formatting_keeps_history_and_unread_counts(): void
    {
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/investor-investment/messages', ['subject' => 'Deposit', 'body_html' => '<p>When will it <u>clear</u>?</p>', 'department' => 'financial'])
            ->assertStatus(201);

        $this->assertSame(1, $this->fin()->getJson('/api/financial/messages')->assertOk()->json('total_unread'));
        $thread = $this->fin()->getJson("/api/financial/messages/{$this->investor->id}")->assertOk()->json('thread');
        $this->assertStringContainsString('<u>clear</u>', $thread[0]['body_html']);
        $this->assertSame('investor', $thread[0]['from']);
        $this->assertSame(0, $this->fin()->getJson('/api/financial/messages/unread-count')->json('unread'));

        $this->fin()->postJson($this->sendUrl(), ['body_html' => '<p>Within <strong>24 hours</strong>.</p>'])->assertStatus(201);
        $this->assertCount(2, $this->actingAs($this->investor, 'sanctum')->getJson('/api/investor-investment/messages')->json('thread'));
    }

    public function test_a_formatted_broadcast_gives_every_recipient_the_same_formatted_text_and_keeps_audit_and_notice_behaviour(): void
    {
        $res = $this->fin()->postJson('/api/financial/messages/broadcast', [
            'scope' => 'all', 'subject' => 'Policy update', 'body_html' => '<p>New <strong>fee</strong> schedule.</p><script>alert(1)</script>', 'kind' => 'notice',
        ])->assertStatus(201);

        $this->assertSame(2, $res->json('recipient_count'));
        $rows = Message::where('broadcast_id', $res->json('broadcast_id'))->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('notice', $r->kind);
            $this->assertSame('New fee schedule.', $r->body);
            $this->assertStringContainsString('<strong>fee</strong>', $r->body_html);
            $this->assertStringNotContainsStringIgnoringCase('<script', $r->body_html);
        }
        $this->assertSame(2, FinancialAuditLog::where('action', 'communication.broadcast_issued')->count());

        $hist = $this->fin()->getJson('/api/financial/messages/broadcasts/' . $res->json('broadcast_id'))->assertOk();
        $this->assertStringContainsString('<strong>fee</strong>', json_encode($hist->json(), JSON_UNESCAPED_SLASHES));
    }

    public function test_broadcast_validation_is_unchanged_and_runs_before_anything_is_created(): void
    {
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'selected', 'subject' => 's', 'body' => 'b'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'all', 'body' => 'b'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'all', 'subject' => 's'])->assertStatus(422);
        $this->fin()->postJson('/api/financial/messages/broadcast', ['scope' => 'all', 'subject' => 's', 'body_html' => '<p><br></p>'])->assertStatus(422);
        $this->assertSame(0, Message::count());
    }

    public function test_the_email_copy_of_a_formatted_reply_uses_the_sanitised_html_and_plain_replies_keep_the_old_path(): void
    {
        Mail::fake();
        $this->fin()->postJson($this->sendUrl(), ['subject' => 'Update', 'body_html' => '<p>Hello <strong>there</strong></p><img src=x onerror=alert(1)>', 'also_email' => true])->assertStatus(201);
        $rich = SentEmail::where('investor_id', $this->investor->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('<strong>there</strong>', $rich->body_html);
        foreach (['onerror', '<img', '<script'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $rich->body_html);

        $this->fin()->postJson($this->sendUrl($this->other), ['subject' => 'Plain', 'body' => "Line 1\nLine 2 <b>x</b>", 'also_email' => true])->assertStatus(201);
        $plain = SentEmail::where('investor_id', $this->other->id)->latest('id')->firstOrFail();
        $this->assertSame('<p>Line 1<br />' . "\n" . 'Line 2 &lt;b&gt;x&lt;/b&gt;</p>', $plain->body_html);          // FinancialMailService::toHtml, byte-for-byte as before
    }

    public function test_hostile_stored_html_is_cleaned_on_read_in_the_financial_inbox_and_broadcast_detail(): void
    {
        Message::create(['investor_id' => $this->investor->id, 'sender_id' => $this->financial->id, 'receiver_id' => $this->investor->id, 'subject' => 'x', 'body' => 'raw', 'body_html' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>',
                         'initiated_by' => 'admin', 'department' => 'financial', 'read_by_financial' => true, 'read_by_investor' => false, 'sender_label' => 'Smart System Investment — Financial Team']);
        $json = json_encode($this->fin()->getJson("/api/financial/messages/{$this->investor->id}")->assertOk()->json('thread'), JSON_UNESCAPED_SLASHES);
        foreach (['<script', 'onerror', '<img'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $json);
        $this->assertStringContainsString('<p>ok</p>', $json);
    }

    public function test_permissions_and_admin_deletion_rules_are_unchanged(): void
    {
        $this->fin()->postJson($this->sendUrl(), ['body_html' => '<p>Official record</p>'])->assertStatus(201);
        $id = Message::firstOrFail()->id;
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/messages/{$id}")->assertStatus(403);       // Financial communications cannot be deleted by admin
        $this->assertNotNull(Message::find($id));

        $this->actingAs($this->investor, 'sanctum')->postJson($this->sendUrl(), ['body_html' => '<p>x</p>'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->sendUrl(), ['body_html' => '<p>x</p>'])->assertUnauthorized();
    }
}
