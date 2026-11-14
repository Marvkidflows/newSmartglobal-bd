<?php
// LOCATION: app/Http/Controllers/Concerns/RespondsWithApproval.php
//
// One place that turns "a request was submitted for second approval" into an HTTP response. A freshly created (or still-pending) request answers
// 202 "awaiting approval". But the same Idempotency-Key / an identical payload can return a request that has ALREADY been decided — in that case
// answering "awaiting approval" would be false, so it answers 200 with the real state and pending_approval:false (the UI shows the message, never
// a "submitted" toast for something that is executed / rejected / cancelled / expired / failed).
namespace App\Http\Controllers\Concerns;

use App\Models\FinancialApprovalRequest;

trait RespondsWithApproval
{
    protected function approvalResponse(FinancialApprovalRequest $req, bool $duplicate, string $pendingMessage, array $extra = [])
    {
        if ($req->status === FinancialApprovalRequest::PENDING) {
            return response()->json([
                'message' => $pendingMessage, 'pending_approval' => true, 'approval_request_id' => $req->id,
                'status' => $req->status, 'duplicate' => $duplicate,
            ] + $extra, 202);
        }
        return response()->json([
            'message' => "An identical request (#{$req->id}) already exists and is {$req->status}. Nothing new was submitted.",
            'pending_approval' => false, 'approval_request_id' => $req->id, 'status' => $req->status, 'duplicate' => true,
        ] + $extra, 200);
    }
}
