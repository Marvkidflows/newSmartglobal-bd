<?php
// LOCATION: database/migrations/2026_09_14_175857_repair_deposits_and_withdrawals_columns.php
//
// Fixes drift between migrations and the Deposit/Withdrawal
// models/controllers that resulted from merging two branches.
//
// DEPOSITS:
//   1. Controllers/model read & write `proof_image`, but the only
//      migration that ran created `screenshot_path` instead. No real
//      screenshots exist under the old column, so it's just dropped.
//   2. AdminDepositController::approve()/reject() write `processed_by`,
//      never added to `deposits` (only `approved_by` exists).
//   3. AdminDepositController::hold() writes `held_at`, only ever added
//      to `withdrawals`, never to `deposits`.
//   4. The `status` enum only allows pending/approved/rejected — 'hold'
//      was never added.
//
// WITHDRAWALS:
//   5. AdminWithdrawalController::approve()/reject() write `processed_by`,
//      same gap as deposits — never added to `withdrawals` either.
//   6. The `status` enum has the same missing 'hold' value.
//      (`held_at` already exists on withdrawals — no fix needed there.)
//   7. InvestorWithdrawalController::store() writes `account_details`
//      (a JSON blob of wallet/bank destination info) — this column has
//      NEVER existed on `withdrawals`. Every withdrawal request has been
//      failing outright with an "Unknown column" error. The table only
//      ever got the old per-method columns (`wallet_address`,
//      `bank_name`, `account_number`, `account_name`), which nothing in
//      the codebase reads or writes anymore (superseded by the single
//      JSON column). Any row that happens to have data in those old
//      columns is backfilled into `account_details` before they're
//      dropped, so no destination info is lost.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── deposits ─────────────────────────────────────────────────
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'screenshot_path')) {
                $table->dropColumn('screenshot_path'); // dead column, unused, no data to keep
            }
            if (!Schema::hasColumn('deposits', 'proof_image')) {
                $table->string('proof_image')->nullable();
            }
            if (!Schema::hasColumn('deposits', 'processed_by')) {
                $table->foreignId('processed_by')->nullable()->after('processed_at')
                      ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('deposits', 'held_at')) {
                $table->timestamp('held_at')->nullable()->after('processed_by');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE deposits MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'hold') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('deposits', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected', 'hold'])->default('pending')->change();
            });
        }

        // ── withdrawals ──────────────────────────────────────────────
        Schema::table('withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('withdrawals', 'processed_by')) {
                $table->foreignId('processed_by')->nullable()->after('processed_at')
                      ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('withdrawals', 'account_details')) {
                $table->json('account_details')->nullable()->after('method');
            }
        });

        // Backfill account_details from the old per-method columns for any
        // row that already has destination data under them (no-op on a
        // fresh install with no withdrawal rows yet).
        if (Schema::hasColumn('withdrawals', 'wallet_address')) {
            DB::table('withdrawals')
                ->whereNull('account_details')
                ->where(function ($q) {
                    $q->whereNotNull('wallet_address')
                      ->orWhereNotNull('bank_name');
                })
                ->orderBy('id')
                ->get()
                ->each(function ($row) {
                    $details = $row->bank_name
                        ? [
                            'bank_name'      => $row->bank_name,
                            'account_number' => $row->account_number,
                            'account_name'   => $row->account_name,
                          ]
                        : ['wallet_address' => $row->wallet_address];

                    DB::table('withdrawals')->where('id', $row->id)
                        ->update(['account_details' => json_encode($details)]);
                });

            Schema::table('withdrawals', function (Blueprint $table) {
                $table->dropColumn(['wallet_address', 'bank_name', 'account_number', 'account_name']);
            });
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE withdrawals MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'hold') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected', 'hold'])->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'proof_image')) {
                $table->dropColumn('proof_image');
            }
            if (Schema::hasColumn('deposits', 'processed_by')) {
                $table->dropConstrainedForeignId('processed_by');
            }
            if (Schema::hasColumn('deposits', 'held_at')) {
                $table->dropColumn('held_at');
            }
            if (!Schema::hasColumn('deposits', 'screenshot_path')) {
                $table->string('screenshot_path')->nullable();
            }
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawals', 'processed_by')) {
                $table->dropConstrainedForeignId('processed_by');
            }
            if (Schema::hasColumn('withdrawals', 'account_details')) {
                $table->dropColumn('account_details');
            }
            if (!Schema::hasColumn('withdrawals', 'wallet_address')) {
                $table->string('wallet_address')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('account_number')->nullable();
                $table->string('account_name')->nullable();
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE deposits MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE withdrawals MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('deposits', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
            });
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
            });
        }
    }
};
