<?php
// LOCATION: database/migrations/2026_09_14_100002_create_fixture_sync_logs_table.php
//
// Gaming & Prediction — one row per FixtureSyncService run (scheduled or
// manual "Sync Now"), so the admin UI can show "Last Sync: ... / Status:
// Success|Failed / Next Sync: ..." plus a short history instead of that
// information disappearing the moment the request finishes.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['scheduler', 'manual'])->default('manual');
            $table->enum('status', ['success', 'partial', 'failed'])->default('success');
            $table->unsignedInteger('fixtures_imported')->default(0);
            $table->unsignedInteger('fixtures_updated')->default(0);
            $table->unsignedInteger('competitions_checked')->default(0);
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_sync_logs');
    }
};
