<?php
// LOCATION: database/migrations/2026_08_26_100001_add_required_amount_override_to_task_assignments_table.php
//
// Lets the admin set a DIFFERENT required amount for individual investors
// sharing the same task code — e.g. investor A needs $500, investor B
// needs $1,000, all using the same code. Task.required_amount remains the
// default for anyone without an override; this column is nullable and
// only takes effect when explicitly set.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->decimal('required_amount', 15, 2)->nullable()->after('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn('required_amount');
        });
    }
};
