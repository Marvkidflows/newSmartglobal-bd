<?php
// LOCATION: tests/Feature/Remediation/MigrationMysqlCompatibilityTest.php
//
// SQLite accepts identifiers of any length; MySQL refuses anything over 64 characters ("Identifier name is too long", error 1059) and aborts the
// migration. This guard runs in the ordinary SQLite suite and fails if any table, column or index created by the migrations would be refused by MySQL.
// It found a real one on 7 Nov 2026: profit_adjustment_items_profit_adjustment_id_investment_id_unique (65 characters), now named explicitly.
//
// STATUS: NOT EXECUTED by its author (no PHP available). Run: php -d memory_limit=-1 vendor\bin\pest --filter=MigrationMysqlCompatibilityTest
// It does NOT replace the real MySQL run (migrate:fresh on a *_test database): it only catches this one class of failure.

namespace Tests\Feature\Remediation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationMysqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_MAX = 64;

    private function sqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('On MySQL the database itself enforces the 64-character limit; this guard is for the SQLite suite.');
        }
    }

    public function test_no_index_name_exceeds_the_mysql_identifier_limit(): void
    {
        $this->sqlite();
        $names = collect(DB::select("select name from sqlite_master where type = 'index' and name not like 'sqlite_autoindex%'"))->pluck('name');
        $this->assertGreaterThan(50, $names->count(), 'the migrations created the indexes this test inspects');

        $tooLong = $names->filter(fn ($n) => strlen($n) > self::MYSQL_MAX)->values()->all();
        $this->assertSame([], $tooLong, 'These index names are longer than 64 characters and would make migrate fail on MySQL: give them an explicit short name.');
    }

    public function test_no_table_or_column_name_exceeds_the_mysql_identifier_limit(): void
    {
        $this->sqlite();
        $bad = [];
        foreach (Schema::getTableListing() as $table) {
            $table = preg_replace('/^.*\./', '', $table);
            if (strlen($table) > self::MYSQL_MAX) $bad[] = $table;
            foreach (Schema::getColumnListing($table) as $col) {
                if (strlen($col) > self::MYSQL_MAX) $bad[] = "{$table}.{$col}";
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_the_previously_oversized_index_now_has_its_explicit_name(): void
    {
        $this->sqlite();
        $this->assertSame(1, count(DB::select("select name from sqlite_master where type = 'index' and name = 'pai_adjustment_investment_unique'")));
        $this->assertSame(0, count(DB::select("select name from sqlite_master where type = 'index' and length(name) > 64")));
    }
}
