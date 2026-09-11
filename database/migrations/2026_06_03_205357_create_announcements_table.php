<?php
// NOTE: guarded no-op — the announcements table is actually created by the
// earlier-dated 2026_01_31_184031_create_notifications_table.php (misnamed
// on disk, see its header comment). This migration would otherwise fail
// with "table already exists" on any fresh install.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('announcements')) {
            return;
        }

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('type')->default('info');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down()
    {
        // Ownership of this table belongs to the earlier migration —
        // intentionally not dropped here to avoid a double drop.
    }
};
