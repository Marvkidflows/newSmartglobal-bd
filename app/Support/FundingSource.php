<?php

namespace App\Support;

use App\Models\InvestmentAccount;

/**
 * How a position was funded — the single place that answers it (read-only, no side effects).
 *
 *  legacy_deposit_with_plan  opened by approving a deposit that carried a plan (source_deposit_id set) with no linked allocation: the wallet was
 *                            credited and NOT debited, so the position's principal overlaps wallet cash (finding F-02 / decision D1).
 *  wallet_funded_allocation  created by the ledger allocation service (an investment_allocations row links it to a wallet debit) — target model.
 *  wallet_funded_legacy      created by the investor from the wallet while the ledger is OFF: wallet debited, no allocation row.
 */
final class FundingSource
{
    public const LEGACY_DEPOSIT = 'legacy_deposit_with_plan';
    public const ALLOCATION     = 'wallet_funded_allocation';
    public const WALLET_LEGACY  = 'wallet_funded_legacy';

    public static function classify(InvestmentAccount $i, bool $hasAllocation): string
    {
        if ($hasAllocation) return self::ALLOCATION;
        return $i->source_deposit_id ? self::LEGACY_DEPOSIT : self::WALLET_LEGACY;
    }

    public static function label(string $source): string
    {
        return [
            self::LEGACY_DEPOSIT => 'Legacy: opened by a deposit with a plan (wallet not debited)',
            self::ALLOCATION     => 'Target model: allocated from wallet cash (ledger)',
            self::WALLET_LEGACY  => 'Legacy: invested from the wallet (debited, ledger off)',
        ][$source] ?? $source;
    }

    /**
     * The effective, VALIDATED legacy deposit-with-plan setting. Only the exact values 'allow' and 'block' (case/space-insensitive) are valid.
     * Anything else (typo, empty, boolean, array) fails SAFE to 'allow' = today's approved behaviour — a typo must never silently freeze
     * deposits (an unapproved behaviour change) — and is reported as invalid in mode() and the Policy Centre and written to the log.
     * @return array{value:string, valid:bool, raw:string}
     */
    public static function legacyDepositPolicy(): array
    {
        $raw = config('financial.legacy_deposit_with_plan', 'allow');
        $norm = is_string($raw) ? strtolower(trim($raw)) : '';
        if (in_array($norm, ['allow', 'block'], true)) return ['value' => $norm, 'valid' => true, 'raw' => $norm];
        \Illuminate\Support\Facades\Log::warning('financial.legacy_deposit_with_plan has an invalid value; treating as allow (unchanged behaviour).', ['value_type' => gettype($raw)]);
        return ['value' => 'allow', 'valid' => false, 'raw' => is_scalar($raw) ? (string) $raw : gettype($raw)];
    }

    /** True only for a NEW legacy deposit-with-plan approval that the optional freeze must refuse. Pure: reads configuration only. */
    public static function freezesLegacyDepositApproval(?int $investmentPlanId): bool
    {
        return (bool) $investmentPlanId && !config('financial.separate_deposit_approval') && self::legacyDepositPolicy()['value'] === 'block';
    }

    /** Which model is in force right now. Reads configuration only. */
    public static function mode(): array
    {
        $target = (bool) config('financial.separate_deposit_approval') && (bool) config('financial.ledger_enabled');
        return ['mode' => $target ? 'target' : 'legacy',
            'separate_deposit_approval' => (bool) config('financial.separate_deposit_approval'), 'ledger_enabled' => (bool) config('financial.ledger_enabled'),
            'legacy_deposit_with_plan' => self::legacyDepositPolicy()['value'], 'legacy_deposit_with_plan_config_valid' => self::legacyDepositPolicy()['valid'],
            'note' => $target ? 'Both target-model flags are ON in this environment.'
                : 'LEGACY behaviour is in force. The documented Financial Team target model is implemented behind flags and is NOT approved or active.'];
    }
}
