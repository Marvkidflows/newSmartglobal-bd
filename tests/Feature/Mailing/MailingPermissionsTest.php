<?php
// TASK 3 — role-based access, department scoping, invalid/unauthorised requests, audit trail. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\Mailing;

use App\Models\Mailing\MailAuditLog;
use App\Models\Mailing\MailDepartment;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailingPermissionsTest extends TestCase
{
    use RefreshDatabase, MailingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_unauthenticated_and_investor_users_are_refused(): void
    {
        $this->getJson('/api/mailing/public/dashboard')->assertUnauthorized();
        $investor = $this->eligibleInvestor();
        foreach (['/api/mailing/public/dashboard', '/api/mailing/investor/dashboard', '/api/mailing/public/subscribers', '/api/mailing/investor/subscribers', '/api/mailing/audit'] as $url) {
            $this->actingAs($investor, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    public function test_staff_without_a_grant_has_no_access(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/public/dashboard')->assertForbidden();
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/investor/dashboard')->assertForbidden();
    }

    public function test_public_permissions_do_not_open_investor_mailing(): void
    {
        $staff = $this->staff(['public.view', 'public.manage', 'public.send']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/public/dashboard')->assertOk();
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/investor/dashboard')->assertForbidden();
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/investor/subscribers')->assertForbidden();           // investor list protection
        $this->actingAs($staff, 'sanctum')->postJson('/api/mailing/investor/campaigns', $this->campaignPayload(['audience_type' => 'verified_investors']))->assertForbidden();
    }

    public function test_investor_permissions_do_not_open_public_mailing(): void
    {
        $staff = $this->staff(['investor.view', 'investor.manage']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/investor/dashboard')->assertOk();
        $this->actingAs($staff, 'sanctum')->getJson('/api/mailing/public/dashboard')->assertForbidden();
    }

    public function test_view_manage_and_send_are_separate_levels(): void
    {
        $viewer = $this->staff(['public.view']);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/mailing/public/campaigns')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/mailing/public/subscribers')->assertForbidden();               // needs manage
        $this->actingAs($viewer, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->assertForbidden();

        $manager = $this->staff(['public.view', 'public.manage']);
        $id = $this->actingAs($manager, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload())->assertCreated()->json('campaign.id');
        $this->actingAs($manager, 'sanctum')->postJson("/api/mailing/public/campaigns/$id/send")->assertForbidden();      // needs send
    }

    public function test_essential_notices_need_their_own_permission(): void
    {
        $sender = $this->staff(['investor.view', 'investor.manage', 'investor.send']);
        $p = $this->campaignPayload(['kind' => 'essential', 'audience_type' => 'verified_investors']);
        $this->actingAs($sender, 'sanctum')->postJson('/api/mailing/investor/campaigns', $p)->assertForbidden();

        $withEssential = $this->staff(['investor.view', 'investor.manage', 'investor.send', 'investor.send_essential']);
        $this->actingAs($withEssential, 'sanctum')->postJson('/api/mailing/investor/campaigns', $p)->assertCreated();
    }

    public function test_essential_kind_is_not_available_in_public_mailing(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['kind' => 'essential']))->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_only_admins_can_grant_permissions_and_only_to_staff(): void
    {
        $admin = $this->admin(); $staff = $this->staff(); $other = $this->staff();
        $this->actingAs($staff, 'sanctum')->putJson("/api/mailing/permissions/{$other->id}", ['permissions' => ['public.view']])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/permissions/{$staff->id}", ['permissions' => ['public.view', 'public.manage']])->assertOk();
        $this->assertDatabaseHas('mail_permissions', ['user_id' => $staff->id, 'permission' => 'public.manage', 'granted_by' => $admin->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/permissions/{$staff->id}", ['permissions' => ['root.everything']])->assertStatus(422);
        $investor = $this->eligibleInvestor();
        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/permissions/{$investor->id}", ['permissions' => ['public.view']])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/permissions/{$staff->id}", ['permissions' => ['public.view']])->assertOk();
        $this->assertDatabaseMissing('mail_permissions', ['user_id' => $staff->id, 'permission' => 'public.manage']);
    }

    public function test_message_from_another_environment_is_not_reachable_through_the_wrong_route(): void
    {
        $staff = $this->staff(['public.send', 'public.view']);
        $m = MailMessage::create(['environment' => 'investor', 'direction' => 'outbound', 'kind' => 'support', 'recipient_email' => 'i@example.com', 'subject' => 'S', 'status' => 'failed']);
        $this->actingAs($staff, 'sanctum')->postJson("/api/mailing/public/messages/{$m->id}/retry")->assertNotFound();
        $this->actingAs($staff, 'sanctum')->getJson("/api/mailing/messages/{$m->id}")->assertForbidden();                  // no investor.view, no department membership
    }

    public function test_department_members_see_only_their_departments_correspondence(): void
    {
        $dept = MailDepartment::where('slug', 'general-enquiries')->first();
        $other = MailDepartment::where('slug', 'financial')->first();
        $admin = $this->admin();
        $member = $this->staff();
        $this->actingAs($admin, 'sanctum')->putJson("/api/mailing/departments/{$dept->id}/members", ['members' => [['user_id' => $member->id, 'role' => 'member']]])->assertOk();

        $mine = MailMessage::create(['environment' => 'public', 'direction' => 'inbound', 'kind' => 'enquiry', 'department_id' => $dept->id, 'recipient_email' => 'g@ssi', 'subject' => 'Mine', 'body_html' => '<p>b</p>', 'status' => 'received']);
        $theirs = MailMessage::create(['environment' => 'investor', 'direction' => 'inbound', 'kind' => 'support', 'department_id' => $other->id, 'recipient_email' => 'f@ssi', 'subject' => 'Theirs', 'body_html' => '<p>b</p>', 'status' => 'received']);

        $this->actingAs($member, 'sanctum')->getJson('/api/mailing/my/messages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->actingAs($member, 'sanctum')->getJson("/api/mailing/messages/{$mine->id}")->assertOk()->assertJsonPath('message.body_html', '<p>b</p>');
        $this->actingAs($member, 'sanctum')->getJson("/api/mailing/messages/{$theirs->id}")->assertForbidden();
        $this->assertTrue(MailAuditLog::where('action', 'message.viewed')->where('actor_id', $member->id)->exists());   // staff activity record
    }

    public function test_administrative_actions_are_audited_without_message_bodies(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['body_html' => '<p>SECRET-BODY-TEXT</p>']))->assertCreated();
        $row = MailAuditLog::where('action', 'campaign.created')->first();
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertStringNotContainsString('SECRET-BODY-TEXT', json_encode($row->metadata));
        $this->actingAs($admin, 'sanctum')->getJson('/api/mailing/audit')->assertOk()->assertJsonPath('data.0.action', 'campaign.created');
        $this->actingAs($this->staff(), 'sanctum')->getJson('/api/mailing/audit')->assertForbidden();
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->getJson('/api/mailing/other/dashboard')->assertNotFound();                      // unknown environment
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['kind' => 'bogus']))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->getJson('/api/mailing/public/campaigns/999999')->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns/999999/send')->assertNotFound();
    }

    public function test_script_content_is_stripped_from_campaign_bodies(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/mailing/public/campaigns', $this->campaignPayload(['body_html' => '<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(1)>']))->assertCreated()->json('campaign.id');
        $body = $this->actingAs($admin, 'sanctum')->getJson("/api/mailing/public/campaigns/$id")->json('campaign.body_html');
        $this->assertStringNotContainsString('<script', strtolower($body));
        $this->assertStringNotContainsString('onerror', strtolower($body));
    }
}
