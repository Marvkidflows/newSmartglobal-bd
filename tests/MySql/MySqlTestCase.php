<?php
// LOCATION: tests/MySql/MySqlTestCase.php
// Shared scaffolding for the MySQL-only concurrency tests: real parallel OS processes released at one instant. See MySqlConcurrencyTest for the header.
namespace Tests\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class MySqlTestCase extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        if (getenv('SSI_MYSQL_CONCURRENCY') !== '1') $this->markTestSkipped('Set SSI_MYSQL_CONCURRENCY=1 and use phpunit.mysql.xml (see file header).');
        if (!str_contains((string) getenv('DB_DATABASE'), 'test')) $this->markTestSkipped('Refusing: DB_DATABASE must contain "test".');
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName(), 'These tests are only meaningful on MySQL.');
    }

    /** @param array<int,array{token:string,method:string,uri:string,body:array,headers?:array}> $jobs @return int[] HTTP status per job */
    protected function fireTogether(array $jobs): array
    {
        $start = microtime(true) + 3.0;                         // enough time for every process to boot before the barrier
        $procs = [];
        foreach ($jobs as $j) {
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_worker.php'), (string) $start, $j['token'], $j['method'], $j['uri'], json_encode($j['body']), json_encode($j['headers'] ?? [])];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
            $procs[] = [$p, $pipes];
        }
        $statuses = [];
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            $decoded = json_decode(trim(strrchr("\n" . trim($out), "\n")), true);
            $this->assertIsArray($decoded, "worker produced no result. stdout={$out} stderr={$err}");
            $statuses[] = (int) $decoded['status'];
        }
        return $statuses;
    }

    protected function planId(): int
    {
        return DB::table('investment_plans')->insertGetId(['name' => 'Concurrency plan', 'min_amount' => 1, 'duration_days' => 30, 'profit_percentage' => 10, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return int[] statuses sorted ascending */
    protected function sorted(array $s): array { sort($s); return $s; }
    protected function money($v): string { return number_format((float) $v, 2, '.', ''); }
}
