<?php
// LOCATION: database/migrations/2026_09_11_120000_add_financial_role_to_users_table.php
//
// Financial Team dashboard — adds 'financial' as a third allowed value
// for users.role (previously 'admin'/'investor' only).
//
// The original create_users_table migration was also updated to define
// the enum with 'financial' included from the start, so a genuinely
// fresh install (or the in-memory SQLite database the test suite
// rebuilds from scratch every run) already has the right column and
// this migration has nothing to do there.
//
// This migration exists for databases that already ran the original
// migration before 'financial' was added (e.g. the Railway production
// database) — Laravel never re-runs a migration once it's recorded, so
// those need an explicit ALTER. MySQL's enum type requires a raw
// MODIFY COLUMN statement for this; there's no doctrine/dbal installed
// in this project, so Schema::table(...)->change() isn't available.
//
// SQLite has no real enum type (Laravel emulates it with a CHECK
// constraint), and altering a CHECK constraint there requires a full
// table rebuild — unnecessary here since the original migration already
// covers every SQLite database that matters (a fresh one). This
// migration is a deliberate no-op on SQLite rather than attempting
// that rebuild.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor', 'financial') NOT NULL DEFAULT 'investor'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Reassign any financial users back to investor before narrowing
        // the enum, so the ALTER itself never fails on existing rows.
        DB::table('users')->where('role', 'financial')->update(['role' => 'investor']);
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor') NOT NULL DEFAULT 'investor'");
    }
};
