<?php
// LOCATION: database/migrations/2024_01_01_000007_create_announcements_table.php
//
// NOTE: this file is misnamed on disk (filename says "notifications" but it
// has always created the "announcements" table — the real notifications
// table is created separately in 2026_01_31_..._fix_notifications_table.php
// further down the migration chain). Kept as the canonical announcements
// creator since it's the earliest of two migrations that both tried to
// create this table; the later duplicate
// (2026_06_03_205357_create_announcements_table.php) is now guarded to
// skip instead of failing with "table already exists" on a fresh install.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcements')) {
            return;
        }

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('content');
            $table->text('message')->nullable();             // alias for content
            // Plain string rather than a strict enum() — the app already
            // uses many more values than the original 4 (general,
            // profit_update, investment_opportunity, etc.), which a real
            // MySQL enum() CHECK constraint would reject.
            $table->string('type', 50)->default('general');
            $table->boolean('is_active')->default(true);

            // Created by admin
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
