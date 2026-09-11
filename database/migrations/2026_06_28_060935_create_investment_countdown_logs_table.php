<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_countdown_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
    $table->date('previous_end_date')->nullable();  // ← match model + controller
    $table->date('new_end_date')->nullable();        // ← match model + controller
    $table->string('action');
    $table->integer('days_changed')->nullable();
    $table->string('reason')->nullable();
    $table->timestamps();
});
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_countdown_logs');
    }
};