<?php

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan ssi:e2e-seed — creates THROW-AWAY accounts and a little test data for the LOCAL browser verification (tools/e2e).
 * Refuses to run unless APP_ENV is local/testing and the database host is local or SQLite. Only ever creates/updates rows whose e-mail ends
 * with @ssi-e2e.test; it never reads, modifies or deletes any other user (the existing administrator is untouched). The password comes from
 * --password or E2E_PASSWORD and is never printed. No real money: all amounts are fake test values.
 */
class E2eSeed extends Command
{
    protected $signature = 'ssi:e2e-seed {--password= : Password for the test accounts (or env E2E_PASSWORD)}';
    protected $description = 'LOCAL ONLY: create @ssi-e2e.test accounts + sample deposit/withdrawal/support data for browser verification';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) { $this->error('Refused: APP_ENV must be local or testing.'); return 1; }
        $conn = config('database.default'); $host = (string) config("database.connections.$conn.host", '');
        if ($conn !== 'sqlite' && !in_array($host, ['127.0.0.1', 'localhost', '::1', 'db', 'mysql', 'host.docker.internal'], true)) { $this->error('Refused: database host is not local.'); return 1; }
        $pw = $this->option('password') ?: env('E2E_PASSWORD');
        if (!$pw || strlen($pw) < 10) { $this->error('Provide --password (10+ chars) or E2E_PASSWORD.'); return 1; }
        $make = function (string $key, string $role, array $x = []) use ($pw) {
            $email = "e2e-{$key}@ssi-e2e.test";
            $u = User::where('email', $email)->first();
            if ($u) { $u->forceFill(['password' => Hash::make($pw), 'status' => 'active', 'registration_completed' => true])->save(); return $u; }
            return User::forceCreate(['name' => 'E2E ' . ucfirst($key), 'email' => $email, 'password' => Hash::make($pw), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
                'kyc_status' => 'approved', 'kyc_verified' => true, 'registration_completed' => true] + $x);
        };
        $admin = $make('admin', 'admin'); $fin = $make('finance', 'financial'); $inv = $make('investor', 'investor', ['balance' => 500]); $inv2 = $make('investor2', 'investor', ['balance' => 50]);
        $dead = $make('deactivated', 'investor'); $dead->forceFill(['status' => 'deactivated'])->save();
        $planId = DB::table('investment_plans')->where('name', 'E2E test plan')->value('id')
            ?: DB::table('investment_plans')->insertGetId(['name' => 'E2E test plan', 'min_amount' => 10, 'duration_days' => 30, 'profit_percentage' => 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        if (!Deposit::where('user_id', $inv->id)->exists()) Deposit::create(['user_id' => $inv->id, 'amount' => 100, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'E2E-DEP-1', 'status' => 'pending', 'investment_plan_id' => $planId]);
        $this->info("Seeded: e2e-admin / e2e-finance / e2e-investor / e2e-investor2 / e2e-deactivated @ssi-e2e.test (plan id $planId). Password not printed.");
        return 0;
    }
}
