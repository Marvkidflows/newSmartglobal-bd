<?php
// D-01 (audit 17 Nov 2026): this seeder used to create an administrator ('adminsystem@gmail.com') and a demo investor with PASSWORDS WRITTEN IN THE SOURCE,
// and DatabaseSeeder called it unconditionally. It is now:
//   * LOCAL-ONLY: refuses in production, staging or any unknown APP_ENV, and refuses a remote database even when APP_ENV says local (RefusesUnsafeSeeding).
//     The refusal happens before any query, so nothing is written.
//   * NON-DESTRUCTIVE: it only ever INSERTS a missing account. An account that already exists (matched on e-mail, case-insensitive) is left exactly as it is —
//     password, role, status, tokens, everything. It never updates, deletes, re-roles or re-passwords anything.
//   * FREE OF HARD-CODED CREDENTIALS: each new account gets a random password that is shown once on the console of the person running the seeder, never
//     stored anywhere else and never logged.
//   * It does not attach demo task types / tasks to an administrator that already existed (it cannot know whether that is a real administrator).

namespace Database\Seeders;

use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Database\Seeders\Concerns\RefusesUnsafeSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    use RefusesUnsafeSeeding;

    public function run()
    {
        $this->assertDemoSeedingAllowed();                  // throws before anything is read or written

        $issued = [];                                       // email => one-time password, printed only after the transaction commits

        DB::transaction(function () use (&$issued) {
            [$admin, $adminCreated, $adminPw] = $this->insertIfAbsent('System Admin', 'adminsystem@gmail.com', 'admin', 'starter', 0);
            if ($adminPw !== null) $issued['adminsystem@gmail.com'] = $adminPw;

            [, , $investorPw] = $this->insertIfAbsent('Demo Investor', 'demo@investor.com', 'investor', 'elite', 42850.50);
            if ($investorPw !== null) $issued['demo@investor.com'] = $investorPw;

            if (!$adminCreated) {
                $this->note("Administrator {$admin->email} already exists. It was NOT modified, and no demo tasks were attached to it.");
                return;
            }

            // Task types (required FK on shared_tasks — must exist first). firstOrCreate: safe to run after, or before, TaskTypeSeeder.
            $surveyType   = TaskType::firstOrCreate(['key' => 'activity'], ['label' => 'Activity', 'icon' => '📝', 'description' => 'Simple engagement activities', 'is_active' => true, 'created_by' => $admin->id]);
            $securityType = TaskType::firstOrCreate(['key' => 'security'], ['label' => 'Security', 'icon' => '🔒', 'description' => 'Account security tasks', 'is_active' => true, 'created_by' => $admin->id]);
            $referralType = TaskType::firstOrCreate(['key' => 'referral'], ['label' => 'Referral', 'icon' => '🔗', 'description' => 'Referral and sharing tasks', 'is_active' => true, 'created_by' => $admin->id]);

            // NOTE: 'reward' no longer exists on this table. A fixed payout per task is a product decision, not something this seeder invents.
            $samples = [
                ['SURVEY-SENTIMENT-01', $surveyType,   'Market Sentiment Survey', 'Share your market outlook for this week'],
                ['SECURITY-EMAIL-01',   $securityType, 'Verify Secondary Email',  'Add backup email for account security'],
                ['REFERRAL-SHARE-01',   $referralType, 'Share Referral Link',     'Invite friends to earn bonus rewards'],
            ];
            foreach ($samples as [$code, $type, $title, $description]) {
                Task::firstOrCreate(['task_code' => $code], [
                    'task_type_id' => $type->id,
                    'title'        => $title,
                    'description'  => $description,
                    'status'       => 'active',
                    'activates_at' => now(),
                    'expires_at'   => now()->addWeek(),
                    'created_by'   => $admin->id,
                ]);
            }
        });

        foreach ($issued as $email => $password) {
            $this->note("Demo account created: {$email}");
            $this->note("  One-time password: {$password}");
        }
        if ($issued !== []) {
            $this->warnLine('Save these now. They are not stored anywhere else and will not be shown again. Local development only.');
        }
    }

    /**
     * Inserts the account ONLY if no user has this e-mail. Returns [user, created?, one-time password or null].
     * An existing user is returned untouched: no update, no save, no password change.
     */
    private function insertIfAbsent(string $name, string $email, string $role, string $tier, float|int $balance): array
    {
        $existing = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($existing !== null) {
            return [$existing, false, null];
        }

        $password = Str::password(20, symbols: false);   // 20 letters/digits: no characters the console formatter or a shell could mangle
        $user = User::unguarded(fn () => User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
            'role'     => $role,
            'tier'     => $tier,     // tier is an investment-plan concept; the admin's role already covers access
            'balance'  => $balance,
        ]));

        return [$user, true, $password];
    }

    /** $this->command is null when a test calls the seeder directly; stay quiet then. */
    private function note(string $message): void
    {
        $this->command?->info($message);
    }

    private function warnLine(string $message): void
    {
        $this->command?->warn($message);
    }
}
