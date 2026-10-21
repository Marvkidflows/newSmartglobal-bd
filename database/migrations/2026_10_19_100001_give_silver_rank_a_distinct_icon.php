<?php
// Bronze and Silver both shipped with the 'shield' icon, so they differed only by colour. Give Silver
// its own icon. Additive and idempotent: it only touches a Silver row that still has the old icon, so
// a deliberately customised icon is never overwritten, and re-running it changes nothing.
// Colours, labels and levels are untouched. Writes no financial data.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('investor_ranks')->where('key', 'silver')->where('icon', 'shield')
            ->update(['icon' => 'shield-check', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('investor_ranks')->where('key', 'silver')->where('icon', 'shield-check')
            ->update(['icon' => 'shield', 'updated_at' => now()]);
    }
};
