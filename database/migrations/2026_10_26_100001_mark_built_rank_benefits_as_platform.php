<?php
// The investor rank report (cycle history / consolidated records) and the next-rank eligibility notice are now built.
// This ONLY corrects the informational `fulfilment` label of those catalogue rows from "feature not built" to
// "platform". It does not change any status, approval, date or wording, so every one of them stays "Pending approval"
// (and unavailable to investors) until an authorised administrator enables it with a written approval reference.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'bronze.next-rank-notice', 'gold.performance-summary', 'gold.extra-reporting', 'platinum.performance-summary',
        'platinum.enhanced-reporting', 'diamond.enhanced-reporting', 'elite.highest-reporting',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('rank_benefits')) return;
        DB::table('rank_benefits')->whereIn('key', self::KEYS)->where('fulfilment', 'future_feature')->update(['fulfilment' => 'platform']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('rank_benefits')) return;
        DB::table('rank_benefits')->whereIn('key', self::KEYS)->where('fulfilment', 'platform')->update(['fulfilment' => 'future_feature']);
    }
};
