<?php
// LOCATION: database/migrations/2024_01_01_000005_create_withdrawals_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->decimal('amount', 15, 2);

            // ── Withdrawal Method ─────────────────────────────────────────
            $table->string('method');                        // bitcoin, ethereum, usdt, bank_transfer
            $table->string('withdrawal_method')->nullable(); // alias

            // ── Crypto Details ────────────────────────────────────────────
            $table->string('wallet_address')->nullable();

            // ── Bank Transfer Details ─────────────────────────────────────
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();

            // ── Status ────────────────────────────────────────────────────
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
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
        Schema::dropIfExists('withdrawals');
    }
};
