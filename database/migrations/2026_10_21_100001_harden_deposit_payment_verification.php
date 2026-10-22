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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read-only. Runs BEFORE any schema change so a refusal leaves the database exactly as it was.
        $this->refuseIfDepositsAlreadyHaveSeveralInvestments();

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

    /**
     * A deposit that already has more than one linked investment is a possible double credit. Adding the UNIQUE index
     * over it would fail with a raw SQL error, and "fixing" it automatically would mean deleting or rewriting money
     * records. This migration therefore never touches the data: it stops and lists what a person must review.
     */
    private function refuseIfDepositsAlreadyHaveSeveralInvestments(): void
    {
        if (!Schema::hasTable('investment_accounts') || !Schema::hasColumn('investment_accounts', 'source_deposit_id')
            || Schema::hasIndex('investment_accounts', 'investment_accounts_source_deposit_id_unique')) {
            return;
        }

        $duplicated = DB::table('investment_accounts')
            ->select('source_deposit_id', DB::raw('COUNT(*) as linked'))
            ->whereNotNull('source_deposit_id')
            ->groupBy('source_deposit_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('source_deposit_id')
            ->get();

        if ($duplicated->isEmpty()) {
            return;
        }

        $lines = $duplicated->take(25)->map(function ($row) {
            $ids = DB::table('investment_accounts')->where('source_deposit_id', $row->source_deposit_id)->orderBy('id')->pluck('id')->implode(', ');

            return "deposit #{$row->source_deposit_id} has {$row->linked} investments (ids: {$ids})";
        })->implode('; ');

        $more = $duplicated->count() > 25 ? ' ... and ' . ($duplicated->count() - 25) . ' more' : '';

        throw new \RuntimeException(
            "Cannot add the UNIQUE index on investment_accounts.source_deposit_id: {$duplicated->count()} deposit(s) already have more than one "
            . "linked investment. NO DATA WAS CHANGED. Review each with the Financial team (possible double credit) and resolve it manually - "
            . "do not delete money records automatically - then run the migration again. Affected: {$lines}{$more}"
        );
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
