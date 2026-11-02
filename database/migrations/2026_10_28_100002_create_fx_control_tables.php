<?php
// Financial Team §4–§5: approved exchange-rate register (EMPTY: no provider is approved until documented), immutable conversion
// snapshots with a provisional → verified lifecycle, and the reconciliation queue for historical records with missing,
// inconsistent or incomplete currency metadata. Additive only; nothing existing is read for writing, changed or deleted.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_approved_sources', function (Blueprint $t) {
            $t->id();
            $t->string('currency', 8);                        // the supported fiat currency
            $t->string('reference_currency', 8)->default('USD');
            $t->string('direction', 20)->default('to_reference');
            $t->string('primary_source', 160);
            $t->string('fallback_source', 160)->nullable();
            $t->string('rate_type', 40);                      // e.g. settlement | reference
            $t->unsignedInteger('max_age_hours')->nullable(); // NULL = not yet approved → every rate is treated as needing review
            $t->string('approval_reference', 160);
            $t->unsignedBigInteger('approved_by');
            $t->string('approved_by_name', 120);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['currency', 'effective_from']);
        });

        // Immutable. A correction is a NEW row that supersedes an earlier one, with its reason and approver.
        Schema::create('fx_conversion_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 20);                   // deposit | investment
            $t->unsignedBigInteger('subject_id');
            $t->string('status', 12);                         // provisional | verified | superseded-by-correction rows carry supersedes_id
            $t->string('original_currency', 8);
            $t->decimal('original_amount', 20, 8);
            $t->string('pair', 20);                           // e.g. NGN/USD
            $t->decimal('rate', 24, 12)->nullable();
            $t->string('rate_source', 160)->nullable();
            $t->string('rate_type', 40)->nullable();
            $t->timestamp('rate_timestamp')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->string('conversion_method', 30)->nullable();  // usd_native | settlement_record | reference_rate_manual | reference_rate_live
            $t->decimal('internal_usd', 28, 12)->nullable();  // full-precision product before posting rounding
            $t->decimal('posted_usd', 15, 2)->nullable();     // posted amount (two decimals)
            $t->string('rounding_method', 30)->default('half_up_2dp');
            $t->string('supporting_reference', 160)->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->string('verified_by_name', 120)->nullable();
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->decimal('previous_rate', 24, 12)->nullable(); // corrections keep the original rate beside the replacement
            $t->text('adjustment_reason')->nullable();
            $t->unsignedBigInteger('reconciliation_item_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id', 'id']);
        });

        Schema::create('fx_reconciliation_items', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 20);                   // deposit | investment
            $t->unsignedBigInteger('subject_id');
            $t->string('reason_code', 40);                    // missing_currency | missing_rate | missing_rate_source | stale_rate | unsupported_pair | no_valuation | inconsistent_amount | crypto_unapproved
            $t->text('detail')->nullable();
            $t->string('status', 12)->default('open');        // open | resolved | unresolved
            $t->text('evidence')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolved_by_name', 120)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('snapshot_id')->nullable();
            $t->timestamps();
            $t->unique(['subject_type', 'subject_id', 'reason_code'], 'fx_recon_unique');
            $t->index(['status', 'subject_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_reconciliation_items');
        Schema::dropIfExists('fx_conversion_snapshots');
        Schema::dropIfExists('fx_approved_sources');
    }
};
