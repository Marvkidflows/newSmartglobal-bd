<?php
// LOCATION: tests/Feature/Registration/RegistrationTestCase.php
// Shared fixtures for the Registration Team (Task 2) tests. All data is created in the isolated per-test database (in-memory SQLite per
// phpunit.xml). No real applicant, balance or financial record is touched. STATUS: written; see the Task 2 report for execution status.

namespace Tests\Feature\Registration;

use App\Models\RegistrationApplication;
use App\Models\User;
use App\Services\Registration\RegistrationTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class RegistrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $member;
    protected User $lead;
    protected User $admin;
    protected User $financial;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.visit_tracking_enabled' => true]);
        $this->member    = User::factory()->create(['role' => 'registration_member', 'status' => 'active', 'name' => 'Rhea Member']);
        $this->lead      = User::factory()->create(['role' => 'registration_lead', 'status' => 'active', 'name' => 'Leo Lead']);
        $this->admin     = User::factory()->create(['role' => 'admin', 'status' => 'active', 'name' => 'Ada Admin']);
        $this->financial = User::factory()->create(['role' => 'financial', 'status' => 'active', 'name' => 'Fay Fin']);
    }

    protected function tracker(): RegistrationTracker
    {
        return app(RegistrationTracker::class);
    }

    /** An investor part-way through registration (stage 1 done). */
    protected function applicant(array $over = []): User
    {
        return User::factory()->create($over + [
            'role' => 'investor', 'status' => 'active', 'registration_stage' => 1, 'registration_completed' => false,
            'balance' => 0, 'phone' => '+23480' . random_int(10000000, 99999999),
        ]);
    }

    /** An applicant who finished registration (stage 4) and uploaded KYC documents → Submitted, ready for review. */
    protected function submittedApplicant(bool $withDocuments = true, array $over = []): RegistrationApplication
    {
        $u = $this->applicant($over + ['registration_stage' => 4, 'registration_completed' => true]);
        $this->tracker()->attempt($u, null, false);
        $this->tracker()->stageCompleted($u, 4);
        if ($withDocuments) {
            $u->forceFill([
                'id_type' => 'passport', 'id_number' => 'A1234567', 'id_document_path' => 'https://example.test/id.jpg',
                'selfie_path' => 'https://example.test/selfie.jpg', 'kyc_status' => 'pending',
            ])->save();
            $this->tracker()->kycSubmitted($u);
        }
        return $u->registrationApplication()->first();
    }

    protected function asUser(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }
}
