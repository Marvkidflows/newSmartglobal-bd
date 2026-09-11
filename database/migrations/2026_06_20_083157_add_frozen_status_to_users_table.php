<?php
// LOCATION: database/migrations/2026_06_19_000006_add_frozen_status_to_users.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY status ENUM('active', 'suspended', 'inactive', 'frozen') DEFAULT 'active'"
            );
        } else {
            // SQLite: the original enum() CHECK constraint only allows
            // active/suspended/inactive — re-declare it with 'frozen' added
            // so fresh (e.g. test) databases behave the same as production.
            Schema::table('users', function (Blueprint $table) {
                $table->enum('status', ['active', 'suspended', 'inactive', 'frozen'])->default('active')->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('users')->where('status', 'frozen')->update(['status' => 'suspended']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY status ENUM('active', 'suspended', 'inactive') DEFAULT 'active'"
            );
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('status', ['active', 'suspended', 'inactive'])->default('active')->change();
            });
        }
    }
};
