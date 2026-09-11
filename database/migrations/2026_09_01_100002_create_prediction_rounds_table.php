<?php
// LOCATION: database/migrations/2026_09_01_100002_create_prediction_rounds_table.php
//
// Phase 4 — Gaming & Prediction Functionality, built as a NON-WAGERING
// activity module per the safe default (no real-money betting, no
// external gaming/wagering provider integrated — see PredictionController
// header comment for what would be required to change that).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('asset_symbol', 20);       // e.g. "BTC" — display/reference only, not tied to a real order
            $table->string('question', 255);           // e.g. "Will BTC be up in the next hour?"
            $table->enum('status', ['draft', 'open', 'closed', 'resolved', 'cancelled'])->default('draft');
            $table->timestamp('opens_at')->nullable();
            $table->dateTime('closes_at');             // entries locked after this
            $table->timestamp('resolves_at')->nullable(); // when admin is expected to settle it
            $table->decimal('reference_price', 18, 8)->nullable(); // price at round open, for context only
            $table->decimal('resolution_price', 18, 8)->nullable(); // price used to settle
            $table->enum('outcome', ['up', 'down', 'flat'])->nullable(); // set on resolve
            $table->unsignedInteger('points_stake')->default(10); // fixed points every entrant risks — NOT currency
            $table->unsignedInteger('points_payout')->default(20); // fixed points a correct entrant receives
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'closes_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_rounds');
    }
};
