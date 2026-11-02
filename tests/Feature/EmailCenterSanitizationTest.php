<?php
// LOCATION: tests/Feature/EmailCenterSanitizationTest.php
//
// Email Center (Admin + the Financial copy) and the investor Emails inbox: email HTML is sanitised on WRITE, again on READ (older rows
// were stored raw), and a last time inside the mailable; legitimate formatting, tables and permitted images survive; sending mechanics,
// ownership and read-tracking are unchanged. Runs on SQLite in memory with the mailer faked.

namespace Tests\Feature;

use App\Mail\AdminCustomEmail;
use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailCenterSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $financial;
    protected User $investor;
    protected User $other;

    protected const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    protected const HOSTILE = '<h1>Notice</h1><p>Hi <strong>there</strong></p><script>alert(1)</script><img/src=x/onerror=alert(1)><a href="javascript:alert(1)">x</a><p style="position:fixed;color:#ff0000">y</p>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin     = User::factory()->create(['role' => 'admin']);
        $this->financial = User::factory()->create(['role' => 'financial']);
        $this->investor  = User::factory()->create(['role' => 'investor', 'email' => 'ivy@example.com', 'registration_stage' => 4, 'registration_completed' => true]);
        $this->other     = User::factory()->create(['role' => 'investor', 'email' => 'otto@example.com', 'registration_stage' => 4, 'registration_completed' => true]);
    }

    protected function adm() { return $this->actingAs($this->admin, 'sanctum'); }
    protected function fin() { return $this->actingAs($this->financial, 'sanctum'); }

    protected function assertClean(string $html): void
    {
        foreach (['<script', 'onerror', 'javascript:', 'position:'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $html);
    }

    // ── write ─────────────────────────────────────────────────────────────────

    public function test_admin_send_sanitises_before_storing_and_mailing_but_keeps_legitimate_formatting(): void
    {
        Mail::fake();
        $this->adm()->postJson('/api/admin/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'Notice', 'body_html' => self::HOSTILE])->assertSuccessful();

        $row = SentEmail::firstOrFail();
        $this->assertClean($row->body_html);
        foreach (['<h1>Notice</h1>', '<strong>there</strong>', 'color:#ff0000'] as $keep) $this->assertStringContainsString($keep, $row->body_html);
        Mail::assertSent(AdminCustomEmail::class, function (AdminCustomEmail $m) {
            $this->assertClean($m->bodyHtml);
            return true;
        });
    }

    public function test_a_body_that_is_empty_once_unsafe_content_is_removed_is_refused_and_nothing_is_stored(): void
    {
        Mail::fake();
        $this->adm()->postJson('/api/admin/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'x', 'body_html' => '<script>alert(1)</script>'])->assertStatus(422)->assertJsonValidationErrors('body_html');
        $this->adm()->postJson('/api/admin/email-center/send-test', ['subject' => 'x', 'body_html' => '<iframe src="javascript:1"></iframe>'])->assertStatus(422);
        $this->assertSame(0, SentEmail::count());
        Mail::assertNothingSent();
    }

    public function test_the_size_limit_is_enforced(): void
    {
        Mail::fake();
        $big = '<p>' . str_repeat('a', 1572864) . '</p>';                       // one character past the 1.5 MB limit once wrapped
        $this->adm()->postJson('/api/admin/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'x', 'body_html' => $big])->assertStatus(422);
        $this->assertSame(0, SentEmail::count());
    }

    public function test_bulk_send_stores_sanitised_html_and_still_queues_one_job_per_recipient(): void
    {
        Queue::fake();
        $this->adm()->postJson('/api/admin/email-center/bulk/send', ['subject' => 'Bulk', 'body_html' => self::HOSTILE, 'filter' => 'all'])->assertStatus(200)->assertJsonPath('queued', 2);
        $this->assertSame(2, SentEmail::where('status', 'queued')->count());
        foreach (SentEmail::all() as $e) $this->assertClean($e->body_html);
        Queue::assertPushed(\App\Jobs\SendBulkEmailJob::class, 2);
    }

    public function test_templates_are_sanitised_on_create_update_and_list(): void
    {
        $id = $this->adm()->postJson('/api/admin/email-center/templates', ['name' => 'T', 'subject' => 'S', 'body_html' => self::HOSTILE])->assertSuccessful()->json('template.id');
        $this->assertClean(EmailTemplate::findOrFail($id)->body_html);

        $this->adm()->putJson("/api/admin/email-center/templates/{$id}", ['body_html' => '<p>ok</p><script>alert(1)</script>'])->assertSuccessful();
        $this->assertClean(EmailTemplate::findOrFail($id)->body_html);

        // an old row stored before this check existed is cleaned when it is listed (the stored row is not rewritten)
        $old = EmailTemplate::create(['name' => 'Old', 'subject' => 'S', 'body_html' => self::HOSTILE, 'created_by' => $this->admin->id]);
        $this->assertClean(json_encode($this->adm()->getJson('/api/admin/email-center/templates')->assertOk()->json()));
        $this->assertStringContainsString('<script', EmailTemplate::findOrFail($old->id)->body_html);
    }

    // ── read ──────────────────────────────────────────────────────────────────

    protected function oldHostileEmail(array $over = []): SentEmail
    {
        return SentEmail::create($over + ['admin_id' => $this->admin->id, 'investor_id' => $this->investor->id, 'recipient_name' => 'Ivy', 'recipient_email' => 'ivy@example.com',
                                          'subject' => 'Old email', 'body_html' => self::HOSTILE, 'status' => 'sent', 'sent_at' => now()]);
    }

    public function test_hostile_html_stored_before_this_change_is_cleaned_in_logs_detail_and_the_investor_inbox(): void
    {
        $email = $this->oldHostileEmail();

        $this->assertClean(json_encode($this->adm()->getJson('/api/admin/email-center/logs')->assertOk()->json()));
        $this->assertClean($this->adm()->getJson("/api/admin/email-center/logs/{$email->id}")->assertOk()->json('email.body_html'));

        $seen = $this->actingAs($this->investor, 'sanctum')->getJson("/api/investor-investment/emails/{$email->id}")->assertOk()->json('email.body_html');
        $this->assertClean($seen);
        $this->assertStringContainsString('<strong>there</strong>', $seen);                               // …and the legitimate part is still there

        $this->assertStringContainsString('<script', SentEmail::findOrFail($email->id)->body_html);       // display-only: the stored row is untouched
    }

    public function test_investor_inbox_ownership_and_authentication_are_unchanged(): void
    {
        $email = $this->oldHostileEmail();
        $this->actingAs($this->other, 'sanctum')->getJson("/api/investor-investment/emails/{$email->id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/investor-investment/emails/{$email->id}")->assertUnauthorized();
    }

    public function test_legitimate_email_content_survives_the_whole_round_trip(): void
    {
        Mail::fake();
        $html = '<h2>Statement</h2><table width="100%" cellpadding="6" border="1"><tr><th align="left">Plan</th><td colspan="1" style="padding:8px">A</td></tr></table><hr>'
              . '<p><img src="https://cdn.example.com/logo.png" alt="Logo" width="120"> <img src="' . self::PNG . '" alt="px"></p><ul><li class="ql-indent-1">x</li></ul>'
              . '<p style="font-family:Georgia;font-size:18px;text-align:center;line-height:1.5">styled <a href="https://example.com">link</a></p>';
        $this->adm()->postJson('/api/admin/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'Statement', 'body_html' => $html])->assertSuccessful();

        $id = SentEmail::firstOrFail()->id;
        $seen = $this->actingAs($this->investor, 'sanctum')->getJson("/api/investor-investment/emails/{$id}")->assertOk()->json('email.body_html');
        foreach (['<h2>', '<table', 'cellpadding="6"', 'colspan="1"', '<hr>', 'src="https://cdn.example.com/logo.png"', 'alt="Logo"', self::PNG, 'ql-indent-1', 'font-family:Georgia', 'href="https://example.com"'] as $keep) {
            $this->assertStringContainsString($keep, $seen, "lost: {$keep}");
        }
    }

    // ── Financial copy of the Email Center ────────────────────────────────────

    public function test_financial_email_center_uses_the_shared_sanitiser_and_cleans_old_rows_on_read(): void
    {
        Mail::fake();
        $this->fin()->postJson('/api/financial/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'Notice', 'body_html' => self::HOSTILE])->assertSuccessful();
        foreach (SentEmail::all() as $e) $this->assertClean($e->body_html);

        $old = $this->oldHostileEmail(['department' => 'financial', 'admin_id' => $this->financial->id]);
        $this->assertClean($this->fin()->getJson("/api/financial/email-center/logs/{$old->id}")->assertOk()->json('email.body_html'));
        $this->assertClean(json_encode($this->fin()->getJson('/api/financial/email-center/templates')->assertOk()->json()));
        $this->fin()->postJson('/api/financial/email-center/send', ['investor_id' => $this->investor->id, 'subject' => 'x', 'body_html' => '<script>alert(1)</script>'])->assertStatus(422);
    }

    public function test_the_mailable_sanitises_even_a_row_queued_before_this_change(): void
    {
        $mail = new AdminCustomEmail('Queued earlier', self::HOSTILE, null, null);

        // Laravel merges a Mailable's PUBLIC properties into the view data after ->with(), so the property itself must be clean.
        $this->assertClean($mail->bodyHtml);
        $this->assertClean($mail->buildViewData()['bodyHtml']);

        $html = $mail->render();
        $this->assertClean($html);
        $this->assertStringContainsString('<strong>there</strong>', $html);
        $this->assertStringNotContainsString('<script', (new AdminCustomEmail('x', '<p>ok</p><script>alert(1)</script>'))->render());
    }

    // ── compatibility report (read-only) ──────────────────────────────────────

    public function test_the_compatibility_report_lists_what_would_be_removed_and_never_writes(): void
    {
        $hostile = $this->oldHostileEmail();
        $legit   = $this->oldHostileEmail(['subject' => 'Fine', 'body_html' => '<h2>Statement</h2><p style="font-weight:bold">All good <a href="https://example.com">link</a></p>']);
        EmailTemplate::create(['name' => 'T', 'subject' => 'S', 'body_html' => '<p>ok</p><script>alert(1)</script>', 'created_by' => $this->admin->id]);
        \App\Models\Message::create(['investor_id' => $this->investor->id, 'sender_id' => $this->admin->id, 'receiver_id' => $this->investor->id, 'body' => 'raw', 'body_html' => '<p>ok</p><script>alert(1)</script>',
                                       'initiated_by' => 'admin', 'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);
        $before = [SentEmail::findOrFail($hostile->id)->body_html, SentEmail::findOrFail($legit->id)->body_html];

        $this->artisan('emails:sanitiser-report')
            ->expectsOutputToContain('sent_emails: 2 with HTML, 1 would be changed on display')
            ->expectsOutputToContain('email_templates: 1 with HTML, 1 would be changed on display')
            ->expectsOutputToContain('messages: 1 with HTML, 1 would be changed on display')     // the message tables are covered too (message profile)
            ->expectsOutputToContain('<script>')
            ->assertSuccessful();

        $this->assertSame($before, [SentEmail::findOrFail($hostile->id)->body_html, SentEmail::findOrFail($legit->id)->body_html]);     // nothing was written
    }

    public function test_inline_image_embedding_is_off_by_default_and_every_mail_goes_through_the_one_mailable(): void
    {
        $this->assertFalse((bool) config('mail.embed_inline_images'));       // opt-in until a real Brevo-API test send proves cid: rendering
        $png = '<p><img src="' . self::PNG . '" alt="px"></p>';
        $this->assertStringContainsString('data:image/png;base64', (new AdminCustomEmail('x', $png))->render());   // untouched while the flag is off
    }

    // ── permissions ───────────────────────────────────────────────────────────

    public function test_permissions_are_unchanged(): void
    {
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/admin/email-center/send', ['investor_id' => $this->other->id, 'subject' => 'x', 'body_html' => '<p>x</p>'])->assertForbidden();
        $this->actingAs($this->investor, 'sanctum')->postJson('/api/financial/email-center/send', ['investor_id' => $this->other->id, 'subject' => 'x', 'body_html' => '<p>x</p>'])->assertForbidden();
        $this->fin()->postJson('/api/admin/email-center/send', ['investor_id' => $this->other->id, 'subject' => 'x', 'body_html' => '<p>x</p>'])->assertForbidden();
        $this->assertSame(0, SentEmail::count());
    }
}
