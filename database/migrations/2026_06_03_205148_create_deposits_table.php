<?php
// LOCATION: database/migrations/2024_01_01_000004_create_deposits_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->decimal('amount', 15, 2);

            // ── Payment Info ──────────────────────────────────────────────
            $table->string('payment_method');     // bitcoin, ethereum, usdt, bank_transfer
            $table->string('method')->nullable(); // alias
            $table->string('transaction_reference')->nullable();
            $table->string('reference')->nullable(); // alias

            // ── Status ────────────────────────────────────────────────────
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->string('reject_reason')->nullable();

            // ── Admin Actions ─────────────────────────────────────────────
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
