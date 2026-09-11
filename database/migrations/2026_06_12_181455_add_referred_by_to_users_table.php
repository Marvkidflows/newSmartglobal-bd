<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOTE: referred_by (with its FK constraint) already exists in
        // 0001_01_01_000000_create_users_table.php — guarded here so this
        // migration is a safe no-op on fresh installs, while remaining
        // harmless on the existing production DB where it already ran.
        if (!Schema::hasColumn('users', 'referred_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('referred_by')->nullable()->after('referral_code')
                      ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Ownership of this column belongs to create_users_table's down()
        // (which drops the whole table) — intentionally not dropped here.
    }
};
