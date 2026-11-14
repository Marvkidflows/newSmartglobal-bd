<?php
// LOCATION: app/Services/Rank/RankValuationNote.php
//
// Explains, for a downgrade review, when the shortfall MAY be caused by currency valuation rather than by the investor genuinely failing the rank.
//
// An investment whose currency valuation is missing ("unverified"), stale (its amount changed after it was verified) or unresolved (in the
// currency reconciliation queue) is LEFT OUT of the investor's figures by the ledger. It is not counted as zero and no exchange rate is assumed.
// If any such record exists while a review is open, the review says so and says that Financial verification is needed first.
//
// Read-only: it reads the same ledger the rank engine uses and writes nothing. It never changes a rank, a review or a valuation, and it applies
// no age limit: a valuation is "stale" only when its record was changed after verification (Management has not approved a maximum rate age).

namespace App\Services\Rank;

use App\Services\Ledger\LedgerReaders;
use Illuminate\Support\Facades\DB;

class RankValuationNote
{
    public function __construct(protected RankInvestmentLedger $ledger) {}

    /** @return array{valuation_related:bool,records:int,unverified:int,stale:int,unresolved:int,requires:string,message:string}|null  null = no valuation issue */
    public function forInvestor(int $investorId): ?array
    {
        $facts      = $this->ledger->forUsers([$investorId])[$investorId] ?? [];
        $unverified = $this->ids($facts['unverified'] ?? []);
        $stale      = $this->ids($facts['stale'] ?? []);

        $rows    = DB::table('investment_accounts')->where('user_id', $investorId)->where('status', '!=', 'cancelled')->get(['id', 'source_deposit_id']);
        $blocked = LedgerReaders::blocked(
            $rows->pluck('id')->map(fn ($v) => (int) $v)->all(),
            $rows->pluck('source_deposit_id', 'id')->map(fn ($v) => $v ? (int) $v : null)->all(),
        );
        $unresolved = $this->ids(array_keys(array_filter($blocked, fn ($reason) => $reason === 'reconciliation')));

        $all = array_values(array_unique(array_merge($unverified, $stale, $unresolved)));
        if (!$all) return null;

        return [
            'valuation_related' => true,
            'records'           => count($all),
            'unverified'        => count($unverified),
            'stale'             => count($stale),
            'unresolved'        => count($unresolved),
            'requires'          => 'Financial verification',
            'message'           => $this->message(count($all)),
        ];
    }

    public function message(int $records): string
    {
        $who = $records === 1 ? '1 investment record has' : "{$records} investment records have";

        return "This shortfall may be valuation-related. {$who} a currency valuation that is unverified, stale or unresolved. "
            . 'Those records are left out of the figures: they are not counted as zero and no exchange rate has been assumed. '
            . 'Financial verification is required before deciding. '
            . 'This review does not mean the investor has definitively failed the rank requirements, and the investor\'s rank has not been changed.';
    }

    /** @param array<int,mixed> $v @return int[] */
    protected function ids(array $v): array
    {
        return array_values(array_unique(array_map('intval', $v)));
    }
}
