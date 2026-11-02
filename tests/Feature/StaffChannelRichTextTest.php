<?php
// LOCATION: tests/Feature/StaffChannelRichTextTest.php
//
// Admin ↔ Financial staff channel: optional subject (≤150) and formatted messages; the shared-thread / role-wide-alert behaviour and the
// who-may-post rules are unchanged; legacy rows (no subject, no HTML) still display.

namespace Tests\Feature;

use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffChannelRichTextTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $admin2;
    protected User $fin;
    protected User $fin2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin  = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->admin2 = User::factory()->create(['role' => 'admin', 'name' => 'Abe Admin']);
        $this->fin    = User::factory()->create(['role' => 'financial', 'name' => 'Fay Fin']);
        $this->fin2   = User::factory()->create(['role' => 'financial', 'name' => 'Finn Fin']);
    }

    protected function alerts(User $u): string { return json_encode($u->notifications()->get()->pluck('data')); }

    public function test_admin_posts_a_formatted_message_with_a_subject_and_financial_reads_it(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['subject' => 'Month-end checklist', 'body_html' => '<p>Please <strong>confirm</strong> the totals.</p>'])->assertSuccessful();

        $row = StaffMessage::firstOrFail();
        $this->assertSame('Month-end checklist', $row->subject);
        $this->assertSame('Please confirm the totals.', $row->body);
        $this->assertStringContainsString('<strong>confirm</strong>', $row->body_html);
        $this->assertSame($this->admin->id, $row->sender_id);

        $m = $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/staff-messages')->assertOk()->json('messages.0');
        $this->assertSame('Month-end checklist', $m['subject']);
        $this->assertStringContainsString('<strong>confirm</strong>', $m['body_html']);
    }

    public function test_the_subject_is_optional_plain_text_and_limited_to_150_characters(): void
    {
        $this->actingAs($this->fin, 'sanctum')->postJson('/api/financial/staff-messages', ['body_html' => '<p>No subject here</p>'])->assertSuccessful();
        $this->assertNull(StaffMessage::latest('id')->first()->subject);

        $this->actingAs($this->fin, 'sanctum')->postJson('/api/financial/staff-messages', ['subject' => '<b>Bold</b> <script>x</script>subject', 'body' => 'x'])->assertSuccessful();
        $this->assertStringNotContainsString('<', StaffMessage::latest('id')->first()->subject);

        $this->actingAs($this->fin, 'sanctum')->postJson('/api/financial/staff-messages', ['subject' => str_repeat('s', 151), 'body' => 'x'])->assertStatus(422);
        $this->actingAs($this->fin, 'sanctum')->postJson('/api/financial/staff-messages', ['subject' => str_repeat('s', 150), 'body' => 'x'])->assertSuccessful();
    }

    public function test_the_legacy_plain_post_and_legacy_rows_still_work(): void
    {
        StaffMessage::create(['sender_id' => $this->admin->id, 'body' => 'An old one-line message']);                  // written before the upgrade: no subject, no HTML
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => 'Plain as before'])->assertSuccessful();

        $msgs = $this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/staff-messages')->assertOk()->json('messages');
        $this->assertCount(2, $msgs);
        foreach ($msgs as $m) { $this->assertNull($m['subject']); $this->assertNull($m['body_html']); }
        $this->assertSame(['An old one-line message', 'Plain as before'], array_column($msgs, 'body'));
    }

    public function test_alerts_go_to_every_user_of_the_receiving_role_as_plain_text_only(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['subject' => 'Heads up', 'body_html' => '<p>Body <strong>text</strong><script>alert(1)</script></p>'])->assertSuccessful();

        foreach ([$this->fin, $this->fin2] as $u) {
            $a = $this->alerts($u);
            $this->assertStringContainsString('Heads up', $a);
            foreach (['<p>', '<strong>', '<script', 'alert(1)'] as $bad) $this->assertStringNotContainsString($bad, $a);
        }
        foreach ([$this->admin, $this->admin2] as $u) $this->assertStringNotContainsString('Heads up', $this->alerts($u));    // the sender's own role is not alerted

        $this->actingAs($this->fin, 'sanctum')->postJson('/api/financial/staff-messages', ['body_html' => '<p>Reply <em>received</em></p>'])->assertSuccessful();
        $this->assertStringContainsString('Reply received', $this->alerts($this->admin));
        $this->assertStringContainsString('Reply received', $this->alerts($this->admin2));
    }

    public function test_hostile_html_is_sanitised_on_write_and_read_and_validation_rules_hold(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body_html' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>'])->assertSuccessful();
        foreach (['<script', 'onerror', '<img'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, StaffMessage::firstOrFail()->body_html);

        StaffMessage::create(['sender_id' => $this->admin->id, 'body' => 'raw', 'body_html' => '<p>x</p><script>alert(1)</script>']);
        $json = json_encode($this->actingAs($this->fin, 'sanctum')->getJson('/api/financial/staff-messages')->assertOk()->json('messages'));
        $this->assertStringNotContainsStringIgnoringCase('<script', $json);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', [])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['subject' => 'only a subject'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body_html' => '<p><br></p>'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => str_repeat('a', 5001)])->assertStatus(422);
    }

    public function test_only_admin_and_financial_can_use_the_channel(): void
    {
        $investor = User::factory()->create(['role' => 'investor', 'registration_stage' => 4, 'registration_completed' => true]);
        $mf = User::factory()->create(['role' => 'marvflow_member']);
        foreach ([$investor, $mf] as $u) {
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/staff-messages')->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson('/api/financial/staff-messages')->assertForbidden();
            $this->actingAs($u, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => 'x'])->assertForbidden();
            $this->actingAs($u, 'sanctum')->postJson('/api/financial/staff-messages', ['body' => 'x'])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/staff-messages', ['body' => 'x'])->assertUnauthorized();
        $this->assertSame(0, StaffMessage::count());
    }

    public function test_the_marvflow_system_is_separate_and_untouched(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/staff-messages', ['body' => 'hello'])->assertSuccessful();
        $this->assertSame(0, \App\Models\MarvflowMessage::count());
    }
}
