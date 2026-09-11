<?php
// LOCATION: database/migrations/2026_06_13_000003_add_plan_and_screenshot_to_deposits_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'investment_plan_id')) {
                $table->foreignId('investment_plan_id')
                      ->nullable()
                      ->after('user_id')
                      ->constrained('investment_plans')
                      ->onDelete('set null');
            }

            if (!Schema::hasColumn('deposits', 'screenshot_path')) {
                $table->string('screenshot_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'investment_plan_id')) {
                $table->dropForeign(['investment_plan_id']);
                $table->dropColumn('investment_plan_id');
            }
            if (Schema::hasColumn('deposits', 'screenshot_path')) {
                $table->dropColumn('screenshot_path');
            }
        });
    }
};