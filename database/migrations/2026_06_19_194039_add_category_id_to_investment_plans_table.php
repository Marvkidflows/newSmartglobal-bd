<?php
// LOCATION: database/migrations/2026_06_19_000005_add_category_id_to_investment_plans.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_plans', 'sector_category_id')) {
                $table->foreignId('sector_category_id')
                      ->nullable()
                      ->after('description')
                      ->constrained('sector_categories')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_plans', function (Blueprint $table) {
            if (Schema::hasColumn('investment_plans', 'sector_category_id')) {
                $table->dropConstrainedForeignId('sector_category_id');
            }
        });
    }
};