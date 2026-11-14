
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure announcements.created_by can be NULL.
     */
    public function up(): void
    {
        if (!Schema::hasTable('announcements') ||
            !Schema::hasColumn('announcements', 'created_by')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    /**
     * The original announcements schema also allows NULL.
     * Do not change this column to NOT NULL during rollback.
     */
    public function down(): void
    {
        // Intentionally leave created_by nullable.
    }
};
