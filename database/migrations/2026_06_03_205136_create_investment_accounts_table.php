<?php
// LOCATION: database/migrations/2024_01_01_000003_create_investment_accounts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->foreignId('investment_plan_id')
                  ->constrained()
                  ->onDelete('restrict');

            // ── Investment Details ────────────────────────────────────────
            $table->decimal('amount', 15, 2);                    // amount invested
            $table->decimal('profit_percentage', 8, 2)->default(0); // ROI %
            $table->decimal('expected_profit', 15, 2)->default(0);  // calculated profit

            // ── Duration ─────────────────────────────────────────────────
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('remaining_days')->nullable();

            // ── Status ────────────────────────────────────────────────────
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_accounts');
    }
};
