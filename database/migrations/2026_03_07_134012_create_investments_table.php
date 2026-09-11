<?php
// database/migrations/2024_03_08_000001_create_investments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('plan_name'); // "Starter Plan", "Professional Plan", "Elite Plan"
            $table->string('tier'); // "Starter", "Professional", "Elite"
            $table->decimal('amount', 12, 2); // Investment amount
            $table->decimal('roi_percentage', 5, 2); // 7%, 12%, 20%
            $table->integer('duration_days')->default(30); // 30 days
            $table->decimal('expected_return', 12, 2); // Calculated expected return
            $table->decimal('earned_return', 12, 2)->default(0); // Actual earned so far
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};