<?php
// LOCATION: tests/Feature/MarvflowStaffContactTelegramTest.php
//
// When SSI staff (Financial Team / Admin) CONTACT MarvFlow by replying, the MarvFlow team's Telegram chat is alerted.
// Telegram is faked: nothing leaves the test. Message text must never be sent to Telegram, and a Telegram problem must never break a reply.

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarvflowStaffContactTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.marvflow_chat_id' => '-100777']);
        $this->owner = User::factory()->create(['role' => 'marvflow_member', 'name' => 'Mara Dev']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->fin   = User::factory()->create(['role' => 'financial', 'name' => 'Fay Finance']);
    }

    protected function fakeTelegram(int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => $status === 200], $status)]);
    }

    /** Telegram calls whose text is the new staff-contact alert (the "new request" alert is a different message). */
    protected function contactAlerts()
    {
        return Http::recorded()->filter(fn ($pair) => str_contains((string) ($pair[0]['text'] ?? ''), 'SSI STAFF CONTACTED MARVFLOW'));
    }

    protected function newRequest(User $as): int
    {
        return $this->actingAs($as, 'sanctum')->postJson("/api/{$as->role}/dev-requests", [
            'subject' => 'Need the report export', 'message' => 'Please add the export.', 'priority' => 'normal',
        ])->assertStatus(201)->json('request.id');
    }

    public function test_a_financial_reply_on_a_request_alerts_the_marvflow_chat_without_the_message_text(): void
    {
        $this->fakeTelegram();
        $id = $this->newRequest($this->fin);

        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/dev-requests/{$id}/reply", ['body' => 'SECRET-BODY-TEXT-123'])->assertOk();

        $alerts = $this->contactAlerts();
        $this->assertCount(1, $alerts);
        $sent = $alerts->first()[0];
        $this->assertSame('-100777', (string) $sent['chat_id']);
        $this->assertStringContainsString('Fay Finance', $sent['text']);
        $this->assertStringContainsString('Financial Team', $sent['text']);
        $this->assertStringContainsString("Request #{$id}", $sent['text']);
        $this->assertStringNotContainsString('SECRET-BODY-TEXT-123', $sent['text']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/bottest-token/sendMessage'));
    }

    public function test_an_admin_reply_to_a_marvflow_email_alerts_the_marvflow_chat_with_the_subject_only(): void
    {
        $this->fakeTelegram();
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/marvflow/messages', [
            'recipient' => 'admin', 'subject' => 'Currency policy checklist', 'body' => '<p>Please review the plan.</p>', 'client_token' => (string) Str::uuid(),
        ])->assertStatus(201)->json('message.id');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/marvflow-messages/{$id}/reply", [
            'body' => '<p>SECRET-ADMIN-REPLY-456</p>', 'client_token' => (string) Str::uuid(),
        ])->assertStatus(201);

        $alerts = $this->contactAlerts();
        $this->assertCount(1, $alerts);
        $text = $alerts->first()[0]['text'];
        $this->assertStringContainsString('Ada Admin', $text);
        $this->assertStringContainsString('Currency policy checklist', $text);
        $this->assertStringNotContainsString('SECRET-ADMIN-REPLY-456', $text);
    }

    public function test_the_reply_still_saves_when_the_marvflow_chat_is_not_configured(): void
    {
        config(['services.telegram.marvflow_chat_id' => null]);
        $this->fakeTelegram();
        $id = $this->newRequest($this->admin);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/dev-requests/{$id}/reply", ['body' => 'Hello'])->assertOk();

        $this->assertCount(0, $this->contactAlerts());
        Http::assertNothingSent();
    }

    public function test_the_reply_still_saves_when_telegram_is_down(): void
    {
        $this->fakeTelegram(500);
        $id = $this->newRequest($this->fin);

        $this->actingAs($this->fin, 'sanctum')->postJson("/api/financial/dev-requests/{$id}/reply", ['body' => 'Hello'])->assertOk();

        $this->assertDatabaseHas('team_request_messages', ['team_request_id' => $id, 'body' => 'Hello']);
    }
}
