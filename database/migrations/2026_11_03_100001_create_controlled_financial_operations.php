<?php
// LOCATION: database/migrations/2026_11_03_100001_create_controlled_financial_operations.php
//
// ADDITIVE ONLY (F-03 / F-04 / F-14). Creates two new tables and adds NULLABLE columns; it does not read, change or delete any existing row,
// so it cannot alter any investor balance or investment. Reversible via down(). Run ONLY through the explicit "migrate" deployment role
// (docker-entrypoint.sh) on an isolated staging database first — never automatically on production.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->string('operation', 40);                      // balance_adjust | bulk_balance | profit_adjust | profit_reverse
            $t->json('payload');                              // the exact validated request, replayed on approval
            $t->char('payload_hash', 64);
            $t->string('status', 20)->default('pending');     // pending | executed | rejected | failed
            $t->string('idempotency_key', 100)->nullable()->unique();
            $t->unsignedBigInteger('requested_by');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->json('result')->nullable();
            $t->timestamps();
            $t->index(['status', 'operation']);
            $t->index('requested_by');
        });

        Schema::create('profit_adjustment_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('profit_adjustment_id')->index();
            $t->unsignedBigInteger('investment_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->decimal('before_profit_percentage', 8, 2);
            $t->decimal('before_expected_profit', 15, 2);
            $t->decimal('before_total_return', 15, 2);
            $t->decimal('after_profit_percentage', 8, 2);
            $t->decimal('after_expected_profit', 15, 2);
            $t->decimal('after_total_return', 15, 2);
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['profit_adjustment_id', 'investment_id'], 'pai_adjustment_investment_unique');   // explicit name: the generated one is 65 characters and MySQL allows 64
        });

        Schema::table('profit_adjustments', function (Blueprint $t) {
            $t->string('status', 24)->default('applied');                 // applied | partially_reversed | reversed
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('authorization_reference', 255)->nullable();      // documented authorisation (e.g. minute / ticket reference)
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->text('reversal_reason')->nullable();
        });

        Schema::table('balance_adjustments', function (Blueprint $t) {
            $t->string('idempotency_key', 120)->nullable()->unique();
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('balance_adjustments', function (Blueprint $t) {
            $t->dropUnique(['idempotency_key']);
            $t->dropColumn(['idempotency_key', 'approval_request_id', 'approved_by']);
        });
        Schema::table('profit_adjustments', function (Blueprint $t) {
            $t->dropColumn(['status', 'approval_request_id', 'approved_by', 'authorization_reference', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
        Schema::dropIfExists('profit_adjustment_items');
        Schema::dropIfExists('financial_approval_requests');
    }
};
