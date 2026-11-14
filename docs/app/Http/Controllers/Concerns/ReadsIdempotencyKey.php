<?php
// LOCATION: app/Http/Controllers/Concerns/ReadsIdempotencyKey.php
namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ReadsIdempotencyKey
{
    /** Optional client-supplied Idempotency-Key (header or body field), restricted to a safe character set; null when absent/malformed. */
    protected function idempotencyKeyFrom(Request $request): ?string
    {
        $k = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k) ? $k : null;
    }
}
