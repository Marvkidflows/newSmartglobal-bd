<?php
// LOCATION: database/migrations/2026_09_13_090000_add_deactivation_fields_to_shared_tasks_table.php
//
// Task activation countdown — adds task-level deactivation. Previously
// activate/deactivate only existed per TaskAssignment (one investor at
// a time); an admin wanting to pause the whole shared task before its
// activation window opened had no single action for that, and nothing
// stopped a fresh code submission (submitCode) or the lazy auto-promote
// (TaskAssignment::syncStart()) from activating anyway once activates_at
// passed, even while every assignment sat deactivated. This column is
// the authoritative "the whole task is paused" flag both of those now
// check before allowing activation.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_tasks', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('expires_at');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason')->nullable()->after('deactivated_by');
        });
    }

    public function down(): void
    {
        Schema::table('shared_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });
    }
};
