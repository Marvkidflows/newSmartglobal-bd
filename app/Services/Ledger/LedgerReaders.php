<?php
// LOCATION: app/Services/Ledger/LedgerReaders.php
//
// READ-ONLY helpers the rank ledger uses to see (a) principal already returned/paid out of a position, and (b) positions that are
// excluded from eligibility (disputed / reversed / under review, or whose currency metadata is unresolved). Both degrade to "nothing"
// when the supporting tables do not exist yet, so the rank engine behaves exactly as before on a database that has not been migrated.

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LedgerReaders
{
    /** Movement kinds that reduce a position's principal once COMPLETED. 'release' is informational and never reduces. */
    public const REDUCING = ['return_to_wallet', 'external_payout', 'withdrawal_principal', 'correction'];

    protected static array $tables = [];

    public static function has(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }

    /** Test helper: forget cached table checks. */
    public static function forget(): void
    {
        self::$tables = [];
    }

    /** @param int[] $investmentIds @return array<int, float> investment_id => principal reduced (position-currency units) */
    public static function reductions(array $investmentIds): array
    {
        if (!$investmentIds || !self::has('principal_movements')) return [];

        return DB::table('principal_movements')
            ->whereIn('investment_id', $investmentIds)->where('status', 'completed')->whereIn('kind', self::REDUCING)
            ->groupBy('investment_id')->selectRaw('investment_id, SUM(amount) as total')
            ->pluck('total', 'investment_id')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * @param  int[]  $investmentIds
     * @param  array<int, int|null>  $depositByInvestment  investment_id => source_deposit_id
     * @return array<int, string> investment_id => reason (disputed | reversed | under_review | reconciliation)
     */
    public static function blocked(array $investmentIds, array $depositByInvestment = []): array
    {
        $out = [];
        if (!$investmentIds) return $out;

        if (self::has('investment_flags')) {
            $rows = DB::table('investment_flags as f')->whereIn('f.investment_id', $investmentIds)->where('f.action', 'set')
                ->whereRaw('f.id = (select max(id) from investment_flags where investment_id = f.investment_id)')
                ->get(['f.investment_id', 'f.flag_type']);
            foreach ($rows as $r) $out[(int) $r->investment_id] = (string) $r->flag_type;
        }

        if (self::has('fx_reconciliation_items')) {
            $open = DB::table('fx_reconciliation_items')->where('status', '!=', 'resolved')
                ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('subject_type', 'investment')->whereIn('subject_id', $investmentIds))
                    ->orWhere(fn ($q2) => $q2->where('subject_type', 'deposit')->whereIn('subject_id', array_values(array_filter($depositByInvestment)) ?: [0])))
                ->get(['subject_type', 'subject_id']);
            $invOpen = $open->where('subject_type', 'investment')->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
            $depOpen = $open->where('subject_type', 'deposit')->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
            foreach ($investmentIds as $id) {
                if (isset($out[$id])) continue;
                if (in_array((int) $id, $invOpen, true) || (isset($depositByInvestment[$id]) && in_array((int) $depositByInvestment[$id], $depOpen, true))) {
                    $out[(int) $id] = 'reconciliation';
                }
            }
        }

        return $out;
    }
}
