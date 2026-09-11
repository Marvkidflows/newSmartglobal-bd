<?php
// LOCATION: database/migrations/2026_06_22_000002_fix_kyc_status_default.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY kyc_status VARCHAR(20) DEFAULT 'not_submitted'"
            );
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('kyc_status')->default('not_submitted')->change();
            });
        }

        // Backfill anyone who never actually uploaded a document but is still
        // marked 'pending' from the old default — they haven't submitted anything.
        DB::table('users')
            ->whereNull('id_document_path')
            ->where('kyc_status', 'pending')
            ->update(['kyc_status' => 'not_submitted']);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY kyc_status VARCHAR(20) DEFAULT 'pending'"
            );
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('kyc_status')->default('pending')->change();
            });
        }
    }
};
