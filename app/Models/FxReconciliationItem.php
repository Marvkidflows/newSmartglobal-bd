<?php
// LOCATION: app/Models/FxReconciliationItem.php — reconciliation queue item for a deposit/investment with missing, inconsistent or
// incomplete currency metadata. Status (open → resolved|unresolved) is the ONLY field that changes, and every change is audited
// (rank_benefit_audit, subject_type 'reconciliation'). The record itself is never deleted.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FxReconciliationItem extends Model
{
    protected $table = 'fx_reconciliation_items';
    protected $fillable = ['subject_type', 'subject_id', 'reason_code', 'detail', 'status', 'evidence', 'resolved_by', 'resolved_by_name', 'resolved_at', 'snapshot_id'];
    protected $casts = ['resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Reconciliation items are never deleted.'));
    }
}
