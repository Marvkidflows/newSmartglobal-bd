<?php
// LOCATION: database/migrations/2026_06_19_000003_create_sectors_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // e.g. "Sports Investment"
            $table->string('slug')->unique();        // e.g. "sports-investment"
            $table->text('description')->nullable();
            $table->string('icon')->nullable();       // optional icon/emoji for UI
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};