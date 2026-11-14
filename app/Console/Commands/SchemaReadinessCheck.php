<?php
// LOCATION: app/Console/Commands/SchemaReadinessCheck.php
//
// Read-only startup gate used by docker-entrypoint.sh for web / worker / scheduler. Exits 1 if any migration file has not been applied OR a
// table/column the remediated code relies on is missing. It performs only SELECTs / schema introspection — it never migrates or writes.
// Migrations are applied ONLY by the explicit 'migrate' deployment role (docs/DATABASE_MIGRATION_AND_ROLLBACK.md).
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SchemaReadinessCheck extends Command
{
    protected $signature = 'ssi:schema-check';
    protected $description = 'Read-only: fail (exit 1) if migrations are pending or required tables/columns are missing.';

    /** table => columns the remediated code reads/writes (additions only; extend when a migration adds something code depends on). */
    protected array $required = [
        'financial_approval_requests' => ['operation', 'payload', 'payload_hash', 'status', 'idempotency_key', 'requested_by', 'decided_by', 'target_user_id',
            'amount_usd', 'authorization_reference', 'open_key', 'expires_at', 'cancelled_by', 'execution_attempts', 'last_error'],
        'financial_approval_events' => ['approval_request_id', 'event', 'actor_id', 'from_status', 'to_status', 'created_at'],
        'balance_adjustments' => ['idempotency_key', 'approval_request_id', 'approved_by'],
        'profit_adjustments' => ['approval_request_id'],
    ];

    public function handle(): int
    {
        $problems = [];
        try {
            $migrator = app('migrator');
            if (!$migrator->repositoryExists()) {
                $problems[] = 'The migrations table does not exist — the database has never been migrated.';
            } else {
                $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
                $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));
                if ($pending) $problems[] = count($pending) . ' pending migration(s): ' . implode(', ', array_slice($pending, 0, 10)) . (count($pending) > 10 ? ' …' : '');
            }
            foreach ($this->required as $table => $cols) {
                if (!Schema::hasTable($table)) { $problems[] = "missing table {$table}"; continue; }
                $missing = array_values(array_filter($cols, fn ($c) => !Schema::hasColumn($table, $c)));
                if ($missing) $problems[] = "table {$table} is missing column(s): " . implode(', ', $missing);
            }
        } catch (\Throwable $e) {
            $problems[] = 'could not inspect the database: ' . $e->getMessage();
        }
        if ($problems) {
            foreach ($problems as $p) $this->error($p);
            $this->error('Schema NOT ready. Run the explicit "migrate" deployment job first.');
            return self::FAILURE;
        }
        $this->info('Schema ready: no pending migrations; required tables/columns present.');
        return self::SUCCESS;
    }
}
