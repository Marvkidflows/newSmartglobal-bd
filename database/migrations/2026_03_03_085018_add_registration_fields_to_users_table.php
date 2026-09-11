<?php
// database/migrations/2024_03_03_000001_add_registration_fields_to_users.php
//
// NOTE: This migration originally duplicated ~18 columns that were later
// absorbed directly into 0001_01_01_000000_create_users_table.php (full_name,
// country_code, phone, country, referral_code, date_of_birth, city, state,
// postal_code, employment_status, annual_income_range, source_of_funds,
// investment_experience, risk_tolerance, investment_objectives,
// two_factor_enabled, withdrawal_pin, registration_stage,
// registration_completed). That was harmless on the existing production
// database (this migration already ran there before the duplication
// happened, so Laravel never re-runs it), but it broke every FRESH
// migration — including the in-memory SQLite database used by the test
// suite. Trimmed down here to only the columns that are genuinely unique
// to this migration.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stage 1: Basic Information (terms/risk acceptance only — the
            // rest of "Stage 1" now lives in create_users_table)
            $table->boolean('terms_accepted')->default(false);
            $table->boolean('risk_accepted')->default(false);

            // Stage 2: KYC Verification
            $table->string('id_type')->nullable(); // passport, national_id, drivers_license
            $table->string('id_number')->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->text('residential_address')->nullable();

            // Stage 4: Security Setup (two_factor_secret / backup_codes only —
            // two_factor_enabled and withdrawal_pin now live in create_users_table)
            $table->string('two_factor_secret')->nullable();
            $table->text('backup_codes')->nullable();

            // Verification Status
            $table->boolean('phone_verified')->default(false);
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('kyc_verified')->default(false);
            $table->timestamp('kyc_verified_at')->nullable();
            $table->string('kyc_status')->default('pending'); // pending, under_review, approved, rejected
            $table->text('kyc_rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'terms_accepted',
                'risk_accepted',
                'id_type',
                'id_number',
                'id_document_path',
                'selfie_path',
                'residential_address',
                'two_factor_secret',
                'backup_codes',
                'phone_verified',
                'phone_verified_at',
                'kyc_verified',
                'kyc_verified_at',
                'kyc_status',
                'kyc_rejection_reason',
            ]);
        });
    }
};
