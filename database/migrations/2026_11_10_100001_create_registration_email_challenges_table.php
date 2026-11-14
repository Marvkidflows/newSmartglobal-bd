<?php
// LOCATION: database/migrations/2026_11_10_100001_create_registration_email_challenges_table.php
//
// Registration security (enumeration closure). Ownership proof for a NEW email BEFORE any account exists.
//   * stage 1 no longer creates an account, issues a token, or answers differently for new / unfinished / completed emails;
//   * a code is emailed to the address; only someone who can read that mailbox can create the account (POST /api/register/verify);
//   * only an HMAC of the code is stored; single use; expiry; per-code attempt cap; every request row counts toward a per-email cooldown
//     and hourly cap whatever its delivery state (same rules as registration_resume_challenges).
// Additive: one new table, no existing table is altered. down() drops only this table.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_email_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);                       // lower-cased
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('delivery_status', 10)->default('pending');   // pending (unusable) | sent | failed
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at'], 'reg_email_chal_email_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_email_challenges');
    }
};
