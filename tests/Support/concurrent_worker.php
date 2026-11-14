<?php
// LOCATION: tests/Support/concurrent_worker.php
// TEST SUPPORT ONLY. One OS process = one independent HTTP-kernel request against the REAL (MySQL) test database, released at a common instant.
// usage: php tests/Support/concurrent_worker.php <startEpochFloat> <token> <METHOD> <uri> <jsonBody> [<jsonHeaders>]
//   METHOD = ARTISAN runs the console command given in <uri> (e.g. "investments:update-maturity") instead of an HTTP request (used to race the
//   scheduler's settlement against HTTP approvals). status 200 = exit code 0, 500 otherwise.
// Prints one line of JSON: {"status":<int>}. Refuses to run unless DB_DATABASE contains "test" (never touches a real database).
if (!str_contains((string) getenv('DB_DATABASE'), 'test')) { fwrite(STDERR, "refusing: DB_DATABASE must contain 'test'\n"); exit(3); }
[, $start, $token, $method, $uri, $body] = $argv;
$headers = isset($argv[6]) ? (json_decode($argv[6], true) ?: []) : [];

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if ($method === 'ARTISAN') {
    $console = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
    while (microtime(true) < (float) $start) { usleep(200); }
    $code = $console->call($uri);
    echo json_encode(['status' => $code === 0 ? 200 : 500]), "\n";
    exit(0);
}
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$http->bootstrap();

$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
$request = Illuminate\Http\Request::create($uri, $method, [], [], [], $server, $body);

while (microtime(true) < (float) $start) { usleep(200); }          // barrier: all workers fire together
$response = $http->handle($request);
echo json_encode(['status' => $response->getStatusCode()]), "\n";
