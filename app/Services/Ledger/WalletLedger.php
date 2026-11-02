<?php
// LOCATION: app/Services/Ledger/WalletLedger.php
//
// The wallet ledger (Financial Team §1B): separate classifications for pending deposits, cleared wallet funds, allocated investment
// principal, pending withdrawals and completed payouts (+ principal returned / paid externally / profit credited). Append-only,
// idempotent by entry_key, and written INSIDE the transaction of the financial action it describes, so the two succeed or fail together.
// It records; it never moves money, and it is a complete no-op while financial.ledger_enabled is false.

namespace App\Services\Ledger;

use App\Models\WalletLedgerEntry;
use Illuminate\Support\Str;

class WalletLedger
{
    public const CLASSIFICATIONS = [
        'pending_deposit', 'cleared_wallet_funds', 'wallet_allocation_debit', 'allocated_investment_principal',
        'pending_withdrawal', 'completed_payout', 'principal_returned_to_wallet', 'principal_paid_externally', 'profit_credit',
    ];

    public function enabled(): bool
    {
        return (bool) config('financial.ledger_enabled') && LedgerReaders::has('wallet_ledger_entries');
    }

    public function newGroup(): string
    {
        return 'LG-' . (string) Str::uuid();
    }

    /** @param array<string, mixed> $d */
    public function record(array $d): ?WalletLedgerEntry
    {
        if (!$this->enabled()) return null;
        if (!in_array($d['classification'], self::CLASSIFICATIONS, true)) throw new \InvalidArgumentException('Unknown ledger classification.');

        if (!empty($d['entry_key']) && ($existing = WalletLedgerEntry::where('entry_key', $d['entry_key'])->first())) return $existing;   // idempotent

        return WalletLedgerEntry::create([
            'entry_group' => $d['entry_group'] ?? $this->newGroup(), 'entry_key' => $d['entry_key'] ?? null, 'user_id' => $d['user_id'],
            'classification' => $d['classification'], 'direction' => $d['direction'] ?? 'info', 'amount' => $d['amount'], 'currency' => $d['currency'] ?? 'USD',
            'deposit_id' => $d['deposit_id'] ?? null, 'withdrawal_id' => $d['withdrawal_id'] ?? null, 'investment_id' => $d['investment_id'] ?? null,
            'allocation_id' => $d['allocation_id'] ?? null, 'principal_movement_id' => $d['principal_movement_id'] ?? null,
            'reference' => $d['reference'] ?? null, 'balance_after' => $d['balance_after'] ?? null, 'note' => $d['note'] ?? null,
            'actor_id' => $d['actor_id'] ?? null, 'actor_name' => $d['actor_name'] ?? null, 'created_at' => now(),
        ]);
    }
}
