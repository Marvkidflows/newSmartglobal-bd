<?php
// Quick "Message Admin" messages reuse marvflow_messages (same inbox, read tracking, idempotency, alerts) instead of a
// competing table. ADDITIVE: one defaulted column. Every existing row becomes kind = 'email' and stays readable.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->string('kind', 10)->default('email')->after('recipient_role');   // 'email' (formal, subject) | 'direct' (quick message)
            $table->index(['recipient_role', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->dropIndex(['recipient_role', 'kind', 'created_at']);
            $table->dropColumn('kind');
        });
    }
};
