<?php
// LOCATION: app/Support/FinancialRecordRetention.php   (NEW FILE — D-08)
//
// Deleting a user used to cascade (ON DELETE CASCADE) into withdrawals and other financial rows, erasing money history. Nothing in the application deletes
// users, but a console session, a future admin screen or a careless script could. This guard makes the safe behaviour the default WITHOUT choosing a
// retention period or changing the schema (Management decision D4 is still open): a user that appears in ANY financial or audit table cannot be deleted
// through Eloquent. Deactivate/suspend the account instead.
//
// Limits, stated honestly: it protects Eloquent ->delete()/->forceDelete(); a raw SQL DELETE or a query-builder bulk delete bypasses model events. The
// database-level fix (replace CASCADE with RESTRICT, or add soft deletes) is a schema change on financial tables and waits for decision D4.

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FinancialRecordRetention
{
    /** [table => columns that point at users.id]. Missing tables/columns (older schemas, tests) are skipped. */
    public const TABLES = [
        'withdrawals'                => ['user_id', 'processed_by'],
        'deposits'                   => ['user_id', 'processed_by', 'approved_by'],
        'investment_accounts'        => ['user_id'],
        'investments'                => ['user_id'],
        'balance_adjustments'        => ['user_id', 'admin_id'],
        'profit_adjustments'         => ['admin_id'],
        'transactions'               => ['user_id'],
        'wallet_ledger_entries'      => ['user_id', 'actor_id'],
        'principal_movements'        => ['user_id'],
        'referral_rewards'           => ['referrer_id', 'referred_id'],
        'referral_reward_events'     => ['actor_id'],
        'referral_policies'          => ['changed_by', 'approved_by'],
        'referrals'                  => ['referrer_id', 'referred_id'],
        'withdrawal_fee_policies'    => ['changed_by', 'approved_by'],
        'withdrawal_fee_events'      => ['actor_id'],
        'financial_audit_logs'       => ['actor_id', 'target_user_id'],
        'financial_approval_requests' => ['requested_by', 'decided_by'],
        'financial_approval_events'  => ['actor_id'],
        'loan_applications'          => ['user_id'],
    ];

    /** @return array<string,int> table => number of rows that reference this user (only tables with at least one) */
    public static function holdings(User $user): array
    {
        $found = [];
        foreach (self::TABLES as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) continue;
                $n = DB::table($table)->where($column, $user->getKey())->count();
                if ($n > 0) $found["{$table}.{$column}"] = $n;
            }
        }
        return $found;
    }

    /** Throws when the user has any financial history. Called from User::deleting. */
    public static function assertDeletable(User $user): void
    {
        $held = self::holdings($user);
        if ($held !== []) {
            throw new RuntimeException('Refusing to delete user #' . $user->getKey() . ': financial or audit records reference this account (' . implode(', ', array_keys($held))
                . '). Deactivate or suspend the account instead; deleting it would erase that history. Retention rules await a Management decision.');
        }
    }
}
