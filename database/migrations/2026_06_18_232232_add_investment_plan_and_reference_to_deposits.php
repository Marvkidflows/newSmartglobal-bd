<?php
// LOCATION: database/migrations/2026_06_19_000001_add_investment_plan_and_reference_to_deposits.php

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
                      ->nullOnDelete();
            }

            if (!Schema::hasColumn('deposits', 'transaction_reference')) {
                $table->string('transaction_reference')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'transaction_reference')) {
                $table->dropColumn('transaction_reference');
            }

            if (Schema::hasColumn('deposits', 'investment_plan_id')) {
                $table->dropConstrainedForeignId('investment_plan_id');
            }
        });
    }
};