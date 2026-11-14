<?php
// LOCATION: app/Services/FinancialAuditService.php
//
// Single place that writes financial_audit_logs rows. Call it INSIDE the
// same DB::transaction as the change being audited, so a financial change
// and its audit record commit or roll back together — a change can never
// exist without its trail.

namespace App\Services;

use App\Models\FinancialAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

class FinancialAuditService
{
    public function record(
        User $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?int $investorId = null,
        ?array $previous = null,
        ?array $new = null,
        ?string $reason = null,
    ): FinancialAuditLog {
        return FinancialAuditLog::create([
            'reference'      => 'FAL-' . strtoupper(Str::random(10)),
            'actor_id'       => $actor->id,
            'actor_name'     => $actor->name ?? $actor->full_name ?? 'Unknown',
            'actor_role'     => $actor->role,
            'department'     => $actor->role === 'financial' ? 'financial' : 'admin',
            'action'         => $action,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'investor_id'    => $investorId,
            'previous_value' => $previous,
            'new_value'      => $new,
            'reason'         => $reason,
        ]);
    }
}
