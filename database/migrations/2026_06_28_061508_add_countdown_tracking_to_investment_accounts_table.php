<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->timestamp('last_countdown_update')->nullable()->after('remaining_days');
            $table->unsignedBigInteger('countdown_modified_by')->nullable()->after('last_countdown_update');
            $table->string('countdown_modified_reason')->nullable()->after('countdown_modified_by');
            $table->boolean('is_paid')->default(false)->after('status');

            $table->foreign('countdown_modified_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->dropForeign(['countdown_modified_by']);
            $table->dropColumn(['last_countdown_update', 'countdown_modified_by', 'countdown_modified_reason', 'is_paid']);
        });
    }
};