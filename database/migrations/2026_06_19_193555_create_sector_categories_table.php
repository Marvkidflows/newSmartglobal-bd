<?php
// LOCATION: database/migrations/2026_06_19_000004_create_sector_categories_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sector_id')
                  ->constrained('sectors')
                  ->onDelete('cascade');

            $table->string('name');                  // e.g. "FIFA World Cup"
            $table->string('slug');                  // e.g. "fifa-world-cup"
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['sector_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_categories');
    }
};