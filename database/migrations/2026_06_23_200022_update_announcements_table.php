<?php
// LOCATION: database/migrations/2026_06_22_000003_update_announcements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (!Schema::hasColumn('announcements', 'is_popup')) {
                $table->boolean('is_popup')->default(false)->after('content');
            }
        });

        // Expand the type column to support all 5 types
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE announcements MODIFY type VARCHAR(50) DEFAULT 'general'"
            );
        } else {
            // On a fresh SQLite install the column is already a plain
            // VARCHAR(50) (see create_notifications_table.php), so this is
            // a no-op there — included only for safety/symmetry.
            Schema::table('announcements', function (Blueprint $table) {
                $table->string('type', 50)->default('general')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'is_popup')) {
                $table->dropColumn('is_popup');
            }
        });
    }
};
