<?php
// TASK 3 — shared helpers for the mailing feature tests. WRITTEN, NOT EXECUTED in the build environment (no PHP runtime available there).
namespace Tests\Feature\Mailing;

use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailPermission;
use App\Models\Mailing\MailSubscription;
use App\Models\User;

trait MailingTestHelpers
{
    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** A non-admin staff member holding exactly the given mailing permissions. */
    protected function staff(array $permissions = [], string $role = 'financial'): User
    {
        $u = User::factory()->create(['role' => $role]);
        foreach ($permissions as $p) MailPermission::create(['user_id' => $u->id, 'permission' => $p]);
        return $u;
    }

    protected function eligibleInvestor(array $o = []): User
    {
        return User::factory()->create($o + ['role' => 'investor', 'status' => 'active', 'kyc_verified' => true, 'email_verified_at' => now()]);
    }

    protected function grantSub(string $email, string $purpose, ?User $user = null): void
    {
        $contact = $user ? null : MailContact::firstOrCreate(['email' => strtolower($email)], ['source' => 'newsletter_form']);
        MailSubscription::create(['email' => strtolower($email), 'purpose' => $purpose, 'status' => 'granted', 'contact_id' => $contact?->id, 'user_id' => $user?->id, 'changed_at' => now()]);
    }

    protected function campaignPayload(array $o = []): array
    {
        return $o + [
            'name' => 'Test campaign', 'subject' => 'Hello {{first_name}}', 'body_html' => '<p>Hi {{first_name}}</p>',
            'kind' => 'newsletter', 'audience_type' => 'public_subscribers',
        ];
    }
}
