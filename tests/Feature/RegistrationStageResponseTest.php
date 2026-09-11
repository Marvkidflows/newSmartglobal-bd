<?php
// LOCATION: tests/Feature/RegistrationStageResponseTest.php
//
// Regression test for a bug introduced alongside the registration_stage
// gating in App.jsx (frontend): InvestorRoute/GuestRoute decide whether
// someone has finished registering by checking registration_stage >= 4
// on the user object the backend returns. Both LoginController::login()
// and RegisterController::submitStage4() originally omitted that field
// from their JSON responses — so a real successful login, or finishing
// registration, would leave registration_stage undefined on the
// frontend, get read as "still stage 1", and immediately bounce the
// person straight back to /register. This locks both responses in.

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationStageResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_response_includes_registration_stage_for_a_completed_investor(): void
    {
        $user = User::factory()->create([
            'password'               => bcrypt('password123'),
            'registration_stage'     => 4,
            'registration_completed' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.registration_stage', 4);
    }

    public function test_login_response_gives_admins_stage_four_even_without_the_column_set(): void
    {
        $admin = User::factory()->create([
            'password'               => bcrypt('password123'),
            'role'                   => 'admin',
            'registration_completed' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.registration_stage', 4);
    }

    public function test_stage4_response_includes_registration_stage_so_the_frontend_does_not_bounce_back(): void
    {
        $user = User::factory()->create([
            'registration_stage'     => 3,
            'registration_completed' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/register/stage4', [
            'withdrawal_pin'              => '1234',
            'withdrawal_pin_confirmation' => '1234',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.registration_stage', 4);
        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'registration_stage' => 4, 'registration_completed' => true,
        ]);
    }
}
