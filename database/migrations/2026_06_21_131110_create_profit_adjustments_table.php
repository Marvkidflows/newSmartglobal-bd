<?php
// LOCATION: database/migrations/2026_06_22_000001_create_profit_adjustments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');

            $table->enum('scope', ['global', 'sector', 'plan', 'users']);
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->foreignId('investment_plan_id')->nullable()->constrained('investment_plans')->nullOnDelete();

            $table->decimal('percentage_change', 8, 2); // e.g. +5.00 or -3.50
            $table->integer('affected_count')->default(0);
            $table->text('reason');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_adjustments');
    }
};