<?php
// LOCATION: database/migrations/2026_10_20_100001_add_payment_details_to_deposits.php
//
// Preserves what was ACTUALLY paid when a deposit is arranged through the Telegram representative (or any channel):
// the amount and currency paid, the exchange rate/source/date used to convert it, the payer's own transfer
// reference, the coordination channel, and who verified those details. Every column is nullable and additive —
// existing deposits, balances, approvals and queries are untouched. `amount` keeps its existing meaning
// (the amount the wallet is credited / the investment is opened for on approval).
// `investment_accounts.source_deposit_id` links an investment to the approved deposit that created it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'paid_amount'))          $table->decimal('paid_amount', 20, 2)->nullable();
            if (!Schema::hasColumn('deposits', 'paid_currency'))        $table->string('paid_currency', 8)->nullable();
            if (!Schema::hasColumn('deposits', 'fx_rate'))              $table->decimal('fx_rate', 20, 10)->nullable();
            if (!Schema::hasColumn('deposits', 'rate_source'))          $table->string('rate_source', 160)->nullable();
            if (!Schema::hasColumn('deposits', 'rate_date'))            $table->date('rate_date')->nullable();
            if (!Schema::hasColumn('deposits', 'approved_amount'))      $table->decimal('approved_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('deposits', 'external_reference'))   $table->string('external_reference', 120)->nullable()->index();
            if (!Schema::hasColumn('deposits', 'funding_channel'))      $table->string('funding_channel', 24)->nullable();
            if (!Schema::hasColumn('deposits', 'payment_note'))         $table->text('payment_note')->nullable();
            if (!Schema::hasColumn('deposits', 'payment_verified_by'))  $table->unsignedBigInteger('payment_verified_by')->nullable();
            if (!Schema::hasColumn('deposits', 'payment_verified_at'))  $table->timestamp('payment_verified_at')->nullable();
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_accounts', 'source_deposit_id')) $table->unsignedBigInteger('source_deposit_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            foreach (['paid_amount', 'paid_currency', 'fx_rate', 'rate_source', 'rate_date', 'approved_amount', 'external_reference', 'funding_channel', 'payment_note', 'payment_verified_by', 'payment_verified_at'] as $c) {
                if (Schema::hasColumn('deposits', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('investment_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('investment_accounts', 'source_deposit_id')) $table->dropColumn('source_deposit_id');
        });
    }
};
