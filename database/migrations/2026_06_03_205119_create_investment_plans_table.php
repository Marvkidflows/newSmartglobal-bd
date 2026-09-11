<?php
// LOCATION: database/migrations/2024_01_01_000002_create_investment_plans_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');                              // e.g. "Starter Plan"
            $table->text('description')->nullable();
            $table->decimal('min_amount', 15, 2)->default(1000);
            $table->decimal('max_amount', 15, 2)->nullable();   // null = no limit
            $table->decimal('profit_percentage', 8, 2)->default(0); // monthly ROI %
            $table->decimal('profit_percent', 8, 2)->default(0);    // alias
            $table->integer('duration_days')->default(30);          // e.g. 30, 60, 90
            $table->integer('duration_months')->nullable();          // e.g. 1, 3, 6, 12
            $table->boolean('is_featured')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_plans');
    }
};
