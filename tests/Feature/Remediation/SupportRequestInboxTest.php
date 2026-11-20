<?php
// LOCATION: tests/Feature/Remediation/SupportRequestInboxTest.php   (NEW — 19 Nov)
// Staff inbox for public contact-support requests. WRITTEN, NOT EXECUTED. Run: php artisan test --filter=SupportRequestInboxTest

namespace Tests\Feature\Remediation;

use App\Http\Controllers\Admin\SupportRequestController as C;
use App\Models\FinancialAuditLog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportRequestInboxTest extends TestCase
{
    use RefreshDatabase;

    private function u(string $role, array $x = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active'] + $x);
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u, 'sanctum');
    }

    private function request(User $inv, string $body = 'Please reactivate my account'): Message
    {
        return Message::create(['sender_id' => $inv->id, 'receiver_id' => $this->u('admin')->id, 'investor_id' => $inv->id, 'subject' => C::SUBJECT, 'body' => $body,
            'initiated_by' => 'investor', 'read_by_admin' => false, 'read_by_investor' => true]);
    }

    public function test_public_submission_is_persisted_and_appears_in_the_inbox(): void
    {
        $inv = $this->u('investor', ['email' => 'jane.doe@ssi-test.test']);
        $this->u('admin');
        $this->postJson('/api/public/contact-support', ['email' => 'jane.doe@ssi-test.test', 'body' => 'Help me'])->assertOk();
        $this->assertSame(1, Message::where('subject', C::SUBJECT)->count());
        $this->as($this->u('admin'))->getJson('/api/admin/support-requests')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_only_admin_can_use_the_inbox(): void
    {
        $m = $this->request($this->u('investor'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/support-requests')->assertUnauthorized();
        foreach (['investor', 'financial', 'marvflow'] as $role) {
            $this->as($this->u($role))->getJson('/api/admin/support-requests')->assertForbidden();
            $this->as($this->u($role))->getJson("/api/admin/support-requests/{$m->id}")->assertForbidden();
            $this->as($this->u($role))->patchJson("/api/admin/support-requests/{$m->id}", ['status' => 'closed'])->assertForbidden();
        }
        $this->assertSame(0, DB::table('support_request_states')->count());
    }

    public function test_list_masks_email_and_detail_shows_it_without_changing_state(): void
    {
        $m = $this->request($this->u('investor', ['email' => 'jane.doe@ssi-test.test']));
        $admin = $this->u('admin');
        $list = $this->as($admin)->getJson('/api/admin/support-requests')->assertOk();
        $this->assertStringNotContainsString('jane.doe', $list->getContent());
        $this->assertSame('j*******@ssi-test.test', $list->json('requests.0.email_masked'));
        $this->as($admin)->getJson("/api/admin/support-requests/{$m->id}")->assertOk()->assertJsonPath('request.email', 'jane.doe@ssi-test.test');
        $this->assertFalse((bool) $m->fresh()->read_by_admin);
        $this->assertSame(0, FinancialAuditLog::count());
    }

    public function test_only_public_support_requests_are_listed(): void
    {
        $inv = $this->u('investor');
        $this->request($inv);
        Message::create(['sender_id' => $inv->id, 'receiver_id' => $this->u('admin')->id, 'investor_id' => $inv->id, 'subject' => 'Ordinary chat', 'body' => 'x', 'initiated_by' => 'investor']);
        $this->assertSame(1, $this->as($this->u('admin'))->getJson('/api/admin/support-requests')->json('meta.total'));
    }

    public function test_status_change_is_stored_and_audited_with_actor_and_previous_value(): void
    {
        $m = $this->request($this->u('investor'));
        $admin = $this->u('admin');
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", ['status' => 'closed', 'read' => true, 'note' => 'Reactivated by phone'])->assertOk();
        $row = FinancialAuditLog::where('action', 'support_request.updated')->firstOrFail();
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('open', $row->previous_value['status']);
        $this->assertSame('closed', $row->new_value['status']);
        $this->assertTrue($row->new_value['read']);
        $this->assertSame('closed', DB::table('support_request_states')->where('message_id', $m->id)->value('status'));
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?status=closed')->json('meta.total'));
        $this->assertSame(0, $this->as($admin)->getJson('/api/admin/support-requests?status=open')->json('meta.total'));
    }

    public function test_noop_change_writes_no_audit_row_and_empty_body_is_refused(): void
    {
        $m = $this->request($this->u('investor'));
        $admin = $this->u('admin');
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", ['status' => 'open'])->assertOk();
        $this->assertSame(0, FinancialAuditLog::count());
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", [])->assertStatus(422);
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", ['status' => 'bogus'])->assertStatus(422);
    }

    public function test_search_and_read_filters_and_wildcards_are_literal(): void
    {
        $a = $this->u('investor', ['name' => 'Alice Wonder']);
        $b = $this->u('investor', ['name' => 'Bob Builder']);
        $this->request($a, 'my card was blocked');
        $mb = $this->request($b, '100% sure it is me');
        $admin = $this->u('admin');
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?q=Alice')->json('meta.total'));
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?q=blocked')->json('meta.total'));
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?q=' . urlencode('100%'))->json('meta.total'));
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?q=' . urlencode('%'))->json('meta.total'));   // '%' matches only the literal percent sign, not everything
        $mb->forceFill(['read_by_admin' => true])->save();
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/support-requests?read=unread')->json('meta.total'));
    }

    public function test_audit_rows_stay_immutable(): void
    {
        $m = $this->request($this->u('investor'));
        $this->as($this->u('admin'))->patchJson("/api/admin/support-requests/{$m->id}", ['status' => 'closed'])->assertOk();
        $this->expectException(\LogicException::class);
        FinancialAuditLog::firstOrFail()->update(['reason' => 'tampered']);
    }

    public function test_unknown_email_gets_the_same_answer_and_nothing_is_stored(): void
    {
        $this->u('admin');
        $known = $this->u('investor', ['email' => 'known@ssi-test.test']);
        $a = $this->postJson('/api/public/contact-support', ['email' => 'known@ssi-test.test', 'body' => 'hello there'])->assertOk()->json('message');
        $b = $this->postJson('/api/public/contact-support', ['email' => 'nobody@ssi-test.test', 'body' => 'hello there'])->assertOk()->json('message');
        $this->assertSame($a, $b);                      // no account enumeration
        $this->assertSame(1, Message::where('subject', C::SUBJECT)->count());
    }

    public function test_migration_is_additive_existing_messages_survive_and_default_to_open(): void
    {
        $inv = $this->u('investor');
        $m = $this->request($inv);
        $this->assertSame(1, Message::count());
        $this->assertSame(0, DB::table('support_request_states')->count());
        $this->assertSame('open', $this->as($this->u('admin'))->getJson("/api/admin/support-requests/{$m->id}")->json('request.status'));
        $m->delete();                                   // deleting a message removes only its state row
        $this->assertSame(0, DB::table('support_request_states')->count());
    }

    public function test_other_message_ids_are_not_readable_through_the_inbox(): void
    {
        $inv = $this->u('investor');
        $other = Message::create(['sender_id' => $inv->id, 'receiver_id' => $this->u('admin')->id, 'investor_id' => $inv->id, 'subject' => 'Private chat', 'body' => 'secret', 'initiated_by' => 'investor']);
        $admin = $this->u('admin');
        $this->as($admin)->getJson("/api/admin/support-requests/{$other->id}")->assertNotFound();
        $this->as($admin)->patchJson("/api/admin/support-requests/{$other->id}", ['status' => 'closed'])->assertNotFound();
    }

    public function test_read_toggle_is_persisted_and_audited_both_ways(): void
    {
        $m = $this->request($this->u('investor'));
        $admin = $this->u('admin');
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", ['read' => true])->assertOk();
        $this->assertTrue((bool) $m->fresh()->read_by_admin);
        $this->as($admin)->patchJson("/api/admin/support-requests/{$m->id}", ['read' => false])->assertOk();
        $this->assertFalse((bool) $m->fresh()->read_by_admin);
        $this->assertSame(2, FinancialAuditLog::where('action', 'support_request.updated')->count());
    }
}
