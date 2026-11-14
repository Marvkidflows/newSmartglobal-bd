<?php
// LOCATION: database/migrations/2026_11_07_100001_create_registration_resume_challenges_table.php
//
// Task 2 (security correction). Ownership proof for RESUMING an unfinished registration.
//
// Before this, POST /api/register/stage1 with the email of an unfinished registration silently overwrote that account's password, name and
// phone and returned a login token to the caller — an account takeover for anyone who knew the email. A resume now requires a one-time code
// emailed to the address on file. This table holds those codes:
//   * only an HMAC of the code is stored (a database leak does not reveal live codes);
//   * single use (consumed_at), short expiry, and a per-code attempt cap;
//   * kept separate from users.email_otp because OtpService::verify() returns success WITHOUT checking the code once an email is already
//     verified, so it cannot be used as proof of ownership for an account that has progressed past stage 1.
// Additive only: no existing table is altered.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_resume_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_resume_challenges');
    }
};
