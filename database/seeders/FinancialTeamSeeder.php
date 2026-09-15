<?php
// LOCATION: database/seeders/FinancialTeamSeeder.php
//
// Financial Team dashboard — creates the test financial team account
// requested for development/testing. The password is generated fresh
// each run (Str::password), never hardcoded here or anywhere in the
// codebase, and is only ever shown once via console output — same
// reasoning as not hardcoding it into frontend code. Re-running this
// seeder against an account that already exists does NOT reset its
// password (firstOrCreate leaves an existing row untouched) — delete
// the user first if you actually want a fresh password generated.

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FinancialTeamSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'financialteam@smartsysteminvestment.com';

        if (User::where('email', $email)->exists()) {
            $this->command?->warn("Financial team account already exists ({$email}) — leaving its password unchanged.");
            return;
        }

        $password = Str::password(16);

        User::create([
            'name'                   => 'Financial Team',
            'email'                  => $email,
            'password'               => Hash::make($password),
            'role'                   => 'financial',
            'tier'                   => 'starter',
            'balance'                => 0,
            'registration_stage'     => 4,
            'registration_completed' => true,
            'email_verified_at'      => now(),
        ]);

        $this->command?->info('Financial team test account created:');
        $this->command?->info("  Email:    {$email}");
        $this->command?->info("  Password: {$password}");
        $this->command?->warn('Save this password now — it is not stored anywhere else and will not be shown again.');
    }
}
