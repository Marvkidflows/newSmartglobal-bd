<?php
// LOCATION: database/migrations/2026_10_21_100001_harden_deposit_payment_verification.php
//
// Additive hardening for Telegram-coordinated / multi-currency deposits:
//  • deposits.payment_date                     the date the funds were actually received (required for non-USD payments)
//  • deposits.amount_difference_acknowledged   staff knowingly approved a USD figure that differs from paid × rate
//  • UNIQUE investment_accounts.source_deposit_id   one deposit can open at most ONE investment, even on a retry/race
//    (NULLs — every investment not opened from a deposit — are unaffected).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'payment_date'))                  $table->date('payment_date')->nullable();
            if (!Schema::hasColumn('deposits', 'amount_difference_acknowledged')) $table->boolean('amount_difference_acknowledged')->default(false);
        });

        if (Schema::hasColumn('investment_accounts', 'source_deposit_id')
            && !Schema::hasIndex('investment_accounts', 'investment_accounts_source_deposit_id_unique')) {
            Schema::table('investment_accounts', function (Blueprint $table) {
                $table->unique('source_deposit_id', 'investment_accounts_source_deposit_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('investment_accounts', 'investment_accounts_source_deposit_id_unique')) {
            Schema::table('investment_accounts', fn (Blueprint $t) => $t->dropUnique('investment_accounts_source_deposit_id_unique'));
        }
        Schema::table('deposits', function (Blueprint $table) {
            foreach (['payment_date', 'amount_difference_acknowledged'] as $c) if (Schema::hasColumn('deposits', $c)) $table->dropColumn($c);
        });
    }
};
