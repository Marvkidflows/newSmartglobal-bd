<?php
// LOCATION: database/migrations/2026_09_15_191257_add_total_return_to_investment_accounts_table.php
//
// InvestmentAccount is fillable/cast for `total_return`, and both
// AdminDepositController::approve() and InvestorInvestmentController::store()
// insert it when creating an investment account — but no prior migration
// ever added this column to `investment_accounts`. Every attempt to create
// an investment account (whether from an admin approving a deposit with a
// plan attached, or an investor activating a plan directly) fails with
// "Unknown column 'total_return' in 'field list'", surfacing to the client
// as a generic server error. This adds the missing column.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_accounts', 'total_return')) {
                $table->decimal('total_return', 15, 2)->default(0)->after('expected_profit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('investment_accounts', 'total_return')) {
                $table->dropColumn('total_return');
            }
        });
    }
};