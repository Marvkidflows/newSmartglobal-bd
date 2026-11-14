<?php
// LOCATION: app/Console/Commands/RegistrationCreateStaff.php
//
// Task 2 — creates a Registration Team login. Staff accounts in this codebase are created directly (there is no self-service signup for
// staff), so this is the supported way to add registration_member / registration_lead accounts. The password is prompted for (never passed
// on the command line, never logged). Role/status are set with forceFill because they are deliberately not mass-assignable.

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegistrationCreateStaff extends Command
{
    protected $signature = 'registration:create-staff {email} {name} {--role=registration_member : registration_member | registration_lead}';
    protected $description = 'Create a Registration Team account (you will be prompted for the password)';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (!in_array($role, ['registration_member', 'registration_lead'], true)) {
            $this->error('Role must be registration_member or registration_lead.');
            return self::FAILURE;
        }

        $v = Validator::make(['email' => $this->argument('email')], ['email' => ['required', 'email', 'max:255', 'unique:users,email']]);
        if ($v->fails()) { $this->error($v->errors()->first()); return self::FAILURE; }

        $password = $this->secret('Password (min 12 characters)');
        if (strlen((string) $password) < 12) { $this->error('Password must be at least 12 characters.'); return self::FAILURE; }
        if ($password !== $this->secret('Confirm password')) { $this->error('Passwords did not match.'); return self::FAILURE; }

        $user = new User();
        $user->forceFill([
            'name'                   => $this->argument('name'),
            'full_name'              => $this->argument('name'),
            'email'                  => $this->argument('email'),
            'password'               => Hash::make($password),
            'role'                   => $role,
            'status'                 => 'active',
            'email_verified_at'      => now(),
            'registration_stage'     => 4,
            'registration_completed' => true,
            'balance'                => 0,
        ])->save();

        $this->info("Created {$role} account #{$user->id} ({$user->email}).");
        return self::SUCCESS;
    }
}
