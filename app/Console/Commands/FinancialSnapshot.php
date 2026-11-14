<?php
// LOCATION: app/Console/Commands/FinancialSnapshot.php
//
// READ-ONLY evidence that existing financial records are preserved across a migration / release. Issues SELECTs only; writes ONLY the JSON file given
// by --out (never overwrites without --force, created 0600, refused inside the web root). It cannot change a balance, investment or ledger row.
//
//   php artisan ssi:financial-snapshot --out=/secure/before.json [--hash]            take a snapshot (counts, decimal sums, max id, optional row hash)
//   php artisan ssi:financial-snapshot --compare=/secure/before.json [--strict]       re-measure and compare
//
// LIVE-SITE SEMANTICS: investors keep transacting, so only rows with id <= the snapshot's recorded max id are re-measured.
//   IMMUTABLE tables (append-only: ledger, adjustments, audit log, valuation/FX snapshots, rank change history): ANY difference ⇒ exit 1.
//   MUTABLE tables (users, investment accounts, deposits, withdrawals…) legitimately change on a live database: differences are REPORTED, and only
//   fail with --strict (use --strict on an isolated, quiet staging copy where nothing should have changed).
// --hash adds a sha256 over every row (chunked, ordered by id). Cost grows with table size: run it against a staging copy or off-peak.
namespace App\Console\Commands;

use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialSnapshot extends Command
{
    protected $signature = 'ssi:financial-snapshot {--out= : Write a snapshot JSON here} {--compare= : Compare against a snapshot JSON} {--hash : Include a per-table row hash} {--strict : Treat mutable-table differences as failures} {--force : Overwrite an existing --out file}';
    protected $description = 'Read-only: snapshot / compare counts, decimal sums and row hashes of the financial tables to prove records were preserved.';

    /** table => [money columns summed] */
    protected array $immutable = [
        'balance_adjustments' => ['amount'], 'financial_audit_logs' => [], 'wallet_ledger_entries' => ['amount'], 'principal_movements' => ['amount'],
        'usd_valuations' => [], 'fx_conversion_snapshots' => [], 'investor_rank_changes' => [], 'profit_adjustment_items' => [], 'transactions' => ['amount'],
        'investment_allocations' => ['amount'],
    ];
    protected array $mutable = [
        'users' => ['balance'], 'investment_accounts' => ['amount', 'expected_profit', 'total_return'], 'deposits' => ['amount'], 'withdrawals' => ['amount'],
        'profit_adjustments' => [], 'financial_approval_requests' => [],
    ];
    /** volatile columns excluded from a row hash (they change on a plain login / touch, not on a financial event) */
    protected array $volatile = ['remember_token', 'updated_at', 'last_login_at', 'last_seen_at'];

    public function handle(): int
    {
        if ($this->option('compare')) return $this->compare((string) $this->option('compare'));
        if (!$this->option('out')) { $this->error('Provide --out=FILE (take a snapshot) or --compare=FILE.'); return self::FAILURE; }
        return $this->take((string) $this->option('out'));
    }

    protected function tables(): array
    {
        $out = [];
        foreach ($this->immutable as $t => $c) $out[$t] = ['mutable' => false, 'sums' => $c];
        foreach ($this->mutable as $t => $c) $out[$t] = ['mutable' => true, 'sums' => $c];
        return $out;
    }

    /** @return array<string,mixed>|null null when the table does not exist in this database */
    protected function measure(string $table, array $sumCols, bool $hash, ?int $upToId): ?array
    {
        if (!Schema::hasTable($table)) return null;
        $hasId = Schema::hasColumn($table, 'id');
        $q = fn () => $hasId && $upToId !== null ? DB::table($table)->where('id', '<=', $upToId) : DB::table($table);
        $row = ['count' => (int) $q()->count(), 'max_id' => $hasId ? (int) (DB::table($table)->max('id') ?? 0) : null, 'sums' => []];
        foreach ($sumCols as $col) {
            if (!Schema::hasColumn($table, $col)) continue;
            $row['sums'][$col] = Decimal::money((string) ($q()->sum($col) ?? '0'));
        }
        if ($hash && $hasId) {
            $ctx = hash_init('sha256'); $drop = array_flip($this->volatile);
            $q()->orderBy('id')->chunkById(1000, function ($rows) use ($ctx, $drop) {
                foreach ($rows as $r) hash_update($ctx, json_encode(array_diff_key((array) $r, $drop), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            });
            $row['row_hash'] = hash_final($ctx);
        }
        return $row;
    }

    protected function take(string $out): int
    {
        $real = realpath(dirname($out)) ?: dirname($out);
        if (str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', public_path()))) { $this->error('Refusing to write a financial snapshot inside the public web root.'); return self::FAILURE; }
        if (file_exists($out) && !$this->option('force')) { $this->error("Refusing to overwrite {$out}. Choose another --out or pass --force."); return self::FAILURE; }
        if (!is_dir(dirname($out)) && !@mkdir(dirname($out), 0750, true) && !is_dir(dirname($out))) { $this->error('Cannot create the output directory.'); return self::FAILURE; }

        $snap = ['taken_at' => now()->toIso8601String(), 'connection' => DB::connection()->getDriverName(), 'hash' => (bool) $this->option('hash'), 'tables' => []];
        foreach ($this->tables() as $t => $meta) {
            $m = $this->measure($t, $meta['sums'], (bool) $this->option('hash'), null);
            if ($m === null) { $this->line("  (skipped, table absent) {$t}"); continue; }
            $snap['tables'][$t] = $m + ['mutable' => $meta['mutable']];
            $this->line(sprintf('  %-28s rows %-8d max_id %-8s %s', $t, $m['count'], $m['max_id'] ?? '-', json_encode($m['sums'])));
        }
        $fh = fopen($out, $this->option('force') ? 'w' : 'x');
        if (!$fh) { $this->error("Cannot open {$out}."); return self::FAILURE; }
        @chmod($out, 0600);
        fwrite($fh, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); fclose($fh);
        $this->info("Snapshot written to {$out} (mode 0600). Read-only: no table was modified.");
        return self::SUCCESS;
    }

    protected function compare(string $file): int
    {
        $before = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($before) || !isset($before['tables'])) { $this->error("Not a snapshot file: {$file}"); return self::FAILURE; }
        $useHash = (bool) ($before['hash'] ?? false);
        $bad = 0; $info = 0;
        foreach ($before['tables'] as $t => $b) {
            $sums = array_keys($b['sums'] ?? []);
            $now = $this->measure($t, $sums, $useHash, $b['max_id'] ?? null);
            $mutable = (bool) ($b['mutable'] ?? false);
            if ($now === null) { $this->error(sprintf('  %-28s TABLE MISSING NOW', $t)); $bad++; continue; }
            $diff = [];
            if ($now['count'] !== $b['count']) $diff[] = "rows {$b['count']}→{$now['count']}";
            foreach ($b['sums'] ?? [] as $col => $v) if (!Decimal::eq($v, $now['sums'][$col] ?? '0')) $diff[] = "sum({$col}) {$v}→" . ($now['sums'][$col] ?? 'n/a');
            if ($useHash && ($b['row_hash'] ?? null) !== ($now['row_hash'] ?? null)) $diff[] = 'row hash differs';
            if (!$diff) { $this->line(sprintf('  OK       %-28s unchanged (rows up to id %s)', $t, $b['max_id'] ?? '-')); continue; }
            if ($mutable && !$this->option('strict')) { $this->warn(sprintf('  CHANGED  %-28s %s  [mutable table: expected on a live database]', $t, implode('; ', $diff))); $info++; continue; }
            $this->error(sprintf('  CHANGED  %-28s %s  [%s]', $t, implode('; ', $diff), $mutable ? 'strict' : 'IMMUTABLE table'));
            $bad++;
        }
        if ($bad) { $this->error("{$bad} table(s) FAILED the preservation check."); return self::FAILURE; }
        $this->info('Preservation check passed' . ($info ? " ({$info} mutable table(s) differ, as expected on a live system — use --strict on a quiet staging copy)." : '.'));
        return self::SUCCESS;
    }
}
