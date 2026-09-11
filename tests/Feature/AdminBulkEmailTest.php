<?php
// LOCATION: tests/Feature/AdminBulkEmailTest.php
//
// Confirms the fix: bulk email sending genuinely uses the queue
// (SendBulkEmailJob) instead of sending each email synchronously inline —
// the job class existed before but was never actually dispatched anywhere.

namespace Tests\Feature;

use App\Jobs\SendBulkEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminBulkEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Bulk send must dispatch one SendBulkEmailJob per recipient — not
     *  send synchronously inline, which would block the request and risk
     *  a timeout for larger batches. */
    public function test_bulk_send_dispatches_a_job_per_recipient_instead_of_sending_inline(): void
    {
        Queue::fake();

        $investors = User::factory()->count(3)->create(['role' => 'investor']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/email-center/bulk/send', [
            'subject'   => 'Test Bulk Email',
            'body_html' => '<p>Hello everyone</p>',
            'filter'    => 'all',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('queued', 3);

        Queue::assertPushed(SendBulkEmailJob::class, 3);

        // Records exist as 'queued', not 'sent' — the job (not this
        // request) is responsible for actually sending and updating status.
        $this->assertDatabaseCount('sent_emails', 3);
        $this->assertEquals(3, \App\Models\SentEmail::where('status', 'queued')->count());
    }

    /** All recipients in one bulk send share the same batch_id, so an
     *  admin can track/filter the whole batch together in Email Logs. */
    public function test_bulk_send_recipients_share_a_batch_id(): void
    {
        Queue::fake();

        User::factory()->count(2)->create(['role' => 'investor']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/email-center/bulk/send', [
            'subject'   => 'Batch Test',
            'body_html' => '<p>Body</p>',
            'filter'    => 'all',
        ]);

        $batchIds = \App\Models\SentEmail::pluck('batch_id')->unique();
        $this->assertCount(1, $batchIds);
    }

    /** Non-admins cannot trigger bulk sends. */
    public function test_investor_cannot_bulk_send_email(): void
    {
        $investor = User::factory()->create(['role' => 'investor']);

        $response = $this->actingAs($investor, 'sanctum')->postJson('/api/admin/email-center/bulk/send', [
            'subject' => 'x', 'body_html' => 'y', 'filter' => 'all',
        ]);

        $response->assertStatus(403);
    }
}
