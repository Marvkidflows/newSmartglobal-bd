<?php
// LOCATION: database/migrations/2026_08_22_100001_create_task_types_table.php
//
// Admin-configurable task/activity types (Investor Task Guide, §14).
// New task types are added by inserting a row here — no code deploy required.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();           // e.g. 'activity', 'signal', 'game', 'investment', 'custom'
            $table->string('label');                    // e.g. 'Trading Signal'
            $table->string('icon')->nullable();          // emoji or icon key, admin-settable
            $table->text('description')->nullable();     // admin-facing helper text
            $table->boolean('requires_amount')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_types');
    }
};
