<?php
// LOCATION: database/migrations/2026_11_06_100001_add_registration_roles_to_users_table.php
//
// TASK 2 — Registration Team. Widens the existing users.role enum with two new values. Every existing row keeps its current role.
//
//   registration_member — can monitor registrations, review KYC submissions, record notes, request additional information,
//                         move an application to Under Review, and follow up with applicants.
//   registration_lead   — everything a member can do, plus approve / reject applications and assign applications to others.
//
// Neither role is admin- or financial-equivalent anywhere: they pass ONLY the 'registration.team' middleware, which exposes the
// /api/registration/* routes. They are refused by the admin, financial, marvflow and investor middleware.
//
// MySQL-only raw SQL, guarded exactly like the sibling role migrations so the sqlite test database can still migrate.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor', 'financial', 'marvflow_member', 'marvflow_lead', 'registration_member', 'registration_lead') NOT NULL DEFAULT 'investor'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Refuse to silently demote real accounts: a down() that left rows holding a value the enum no longer accepts would corrupt them.
        if (DB::table('users')->whereIn('role', ['registration_member', 'registration_lead'])->exists()) {
            throw new RuntimeException('Cannot roll back: registration team accounts exist. Reassign or remove them first.');
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor', 'financial', 'marvflow_member', 'marvflow_lead') NOT NULL DEFAULT 'investor'");
    }
};
