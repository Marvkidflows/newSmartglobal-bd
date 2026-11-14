<?php
// TASK 3 — provider webhooks. Authenticated by a shared secret from the environment (MAILING_WEBHOOK_SECRET); disabled when unset.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Services\Mailing\WebhookProcessor;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(protected WebhookProcessor $processor) {}

    public function delivery(Request $r)
    {
        if (!WebhookProcessor::authorised($r)) return response()->json(['message' => 'Unauthorized.'], 401);
        return response()->json(['handled' => $this->processor->delivery($r->json()->all())]);
    }

    public function inbound(Request $r)
    {
        if (!WebhookProcessor::authorised($r)) return response()->json(['message' => 'Unauthorized.'], 401);
        return response()->json(['stored' => $this->processor->inbound($r->json()->all())]);
    }
}
