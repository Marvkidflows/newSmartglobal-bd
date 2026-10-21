<?php
// Verified USD values for investment and deposit records, kept OUTSIDE the financial tables.
//
// The platform stores each amount as a bare decimal with no currency, rate or conversion. Rank thresholds are
// in USD, so the rank engine may only use an amount once a person has recorded what currency it is in (and, if
// it is not USD, the exchange rate used). Nothing here changes investments, deposits or balances.
//
// APPEND-ONLY: a correction is a new row; the newest row for a record is the current valuation. The original
// amount at verification time is snapshotted, so if the record's amount is later corrected the valuation is
// seen as STALE and the record goes back to the review queue instead of being silently trusted.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usd_valuations', function (Blueprint $table) {
            $table->id();
            $table->string('valuable_type', 20);                       // investment | deposit
            $table->unsignedBigInteger('valuable_id');
            $table->unsignedBigInteger('investor_id')->index();
            $table->string('currency', 8);                             // currency the ORIGINAL amount is in
            $table->decimal('original_amount', 15, 2);                 // the record's amount when verified (never edited)
            $table->decimal('fx_rate', 20, 10);                        // 1 for USD; otherwise the recorded rate to USD
            $table->decimal('usd_amount', 15, 2);                      // original_amount × fx_rate, computed server-side
            $table->string('rate_source', 160)->nullable();            // where the rate came from (required if not USD)
            $table->date('rate_date')->nullable();                     // the date the rate applies to (required if not USD)
            $table->boolean('distinct_confirmed')->default(false);     // Financial confirmed a duplicate-looking record is separate
            $table->text('note');
            $table->unsignedBigInteger('verified_by');
            $table->string('verified_by_name', 120);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['valuable_type', 'valuable_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usd_valuations');
    }
};
