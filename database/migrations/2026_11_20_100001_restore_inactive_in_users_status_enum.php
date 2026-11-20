<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 2026_07_07_151311 rewrote users.status as ENUM('active','suspended','frozen','deactivated'), silently dropping 'inactive'. The application
// still validates and uses 'inactive' (AdminUserController, StaffAccountGuard), so on MySQL writing it fails with "Data truncated" (HTTP 500).
// This restores all five values. It only widens the set, so every existing row stays valid. MySQL-only: other drivers keep their own definition.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspended', 'inactive', 'frozen', 'deactivated') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // Intentionally a no-op: narrowing the enum could truncate rows that now hold 'inactive'.
    }
};
