<?php
// Single authoritative rank reference. Nullable = "Unranked".
// Deliberately NOT mass-assignable on the User model.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('investor_rank_id')->nullable()->after('tier')
                ->constrained('investor_ranks')->nullOnDelete();
            $table->timestamp('rank_changed_at')->nullable()->after('investor_rank_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_rank_id');
            $table->dropColumn('rank_changed_at');
        });
    }
};
