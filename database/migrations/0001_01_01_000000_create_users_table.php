<?php
// LOCATION: database/migrations/2024_01_01_000001_create_users_table.php
// Run: php artisan migrate

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ── Basic Info ────────────────────────────────────────────────
            $table->string('name');                          // full name (compatibility)
            $table->string('full_name')->nullable();         // explicit full name
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // ── Role & Status ─────────────────────────────────────────────
            $table->enum('role', ['admin', 'investor'])->default('investor');
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');

            // ── Contact Info ──────────────────────────────────────────────
            $table->string('phone')->nullable();
            $table->string('country_code')->nullable();      // e.g. +234
            $table->string('country')->nullable();           // e.g. Nigeria
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->date('date_of_birth')->nullable();

            // ── Financial ─────────────────────────────────────────────────
            $table->decimal('balance', 15, 2)->default(0);

            // ── Referral ──────────────────────────────────────────────────
            $table->string('referral_code')->nullable()->unique();
            $table->unsignedBigInteger('referred_by')->nullable();

            // ── Registration Progress ─────────────────────────────────────
            $table->integer('registration_stage')->default(1);
            $table->boolean('registration_completed')->default(false);

            // ── Investor Profile ──────────────────────────────────────────
            $table->string('employment_status')->nullable();
            $table->string('annual_income_range')->nullable();
            $table->string('source_of_funds')->nullable();
            $table->string('investment_experience')->nullable();
            $table->string('risk_tolerance')->nullable();
            $table->text('investment_objectives')->nullable();

            // ── Security ──────────────────────────────────────────────────
            $table->string('withdrawal_pin')->nullable();    // hashed 4-digit PIN
            $table->boolean('two_factor_enabled')->default(false);

            // ── Profile Photo ─────────────────────────────────────────────
            $table->string('profile_photo')->nullable();
            $table->string('avatar')->nullable();

            // ── Timestamps ────────────────────────────────────────────────
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // ── Foreign Key ───────────────────────────────────────────────
            $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
