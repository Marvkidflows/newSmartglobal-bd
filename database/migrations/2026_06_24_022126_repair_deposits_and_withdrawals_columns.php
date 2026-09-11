<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }

            if (!Schema::hasColumn('deposits', 'admin_notes')) {
                $table->text('admin_notes')->nullable();
            }
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('withdrawals', 'held_at')) {
                $table->timestamp('held_at')->nullable();
            }

            if (!Schema::hasColumn('withdrawals', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }

            if (!Schema::hasColumn('withdrawals', 'admin_notes')) {
                $table->text('admin_notes')->nullable();
            }
        });
    }

    public function down(): void {}
};