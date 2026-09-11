<?php
// LOCATION: database/migrations/2026_08_25_100001_add_code_confirmed_at_to_task_assignments_table.php
//
// Lets an investor enter their code as soon as they receive it, even before
// the admin's activation window opens. code_confirmed_at marks that moment;
// the assignment then auto-activates the instant the window opens (lazy,
// server-enforced on read — same pattern as syncExpiry()), and the investor
// sees a "starts in" countdown in the meantime instead of a hard rejection.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->timestamp('code_confirmed_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn('code_confirmed_at');
        });
    }
};
