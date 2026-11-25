<?php
// Dashboard messaging + deposit integration. ADDITIVE ONLY: three nullable/defaulted columns on the EXISTING `messages` table.
// No row is modified or backfilled, so all existing correspondence stays exactly as it is (every old row reads as topic = 'general').
//
//  topic         'general' (default) | 'deposit'  — marks a Financial Team message as a deposit enquiry.
//  deposit_id    the deposit the enquiry is about (nullable; NO foreign key, so a deposit record can never be blocked or cascaded
//                by the messaging table, and financial records are never touched by it). Ownership is validated in code.
//  client_token  client-generated key for one send attempt. UNIQUE per investor, so a double click / retry / flaky-network resend
//                returns the SAME message instead of creating a duplicate. NULL (every old row, old clients) never collides.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'topic'))        $table->string('topic', 20)->default('general');
            if (!Schema::hasColumn('messages', 'deposit_id'))   $table->unsignedBigInteger('deposit_id')->nullable()->index();
            if (!Schema::hasColumn('messages', 'client_token')) $table->string('client_token', 64)->nullable();
        });

        if (!Schema::hasIndex('messages', 'messages_investor_client_token_unique')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->unique(['investor_id', 'client_token'], 'messages_investor_client_token_unique');
            });
        }
    }

    /** Only the three added columns are dropped; no message text is lost. */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasIndex('messages', 'messages_investor_client_token_unique')) $table->dropUnique('messages_investor_client_token_unique');
        });
        Schema::table('messages', function (Blueprint $table) {
            foreach (['topic', 'deposit_id', 'client_token'] as $c) {
                if (Schema::hasColumn('messages', $c)) $table->dropColumn($c);
            }
        });
    }
};
