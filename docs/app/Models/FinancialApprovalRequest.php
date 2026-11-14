<?php
// LOCATION: app/Models/FinancialApprovalRequest.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialApprovalRequest extends Model
{
    // State machine (R8). 'approved' is only ever seen on rows written by the earlier two-commit workflow; the current workflow claims and
    // executes in ONE transaction, so 'executing' is never committed — a crash leaves the request exactly as it was (pending/failed).
    public const PENDING = 'pending', EXECUTING = 'executing', EXECUTED = 'executed', REJECTED = 'rejected', FAILED = 'failed',
                 CANCELLED = 'cancelled', EXPIRED = 'expired', LEGACY_APPROVED = 'approved';
    public const OPEN = [self::PENDING, self::FAILED];                                    // can still be decided / retried / cancelled
    public const TERMINAL = [self::EXECUTED, self::REJECTED, self::CANCELLED, self::EXPIRED];

    protected $fillable = ['operation', 'payload', 'payload_hash', 'status', 'idempotency_key', 'requested_by', 'decided_by', 'decision_note',
        'decided_at', 'executed_at', 'result', 'target_user_id', 'amount_usd', 'authorization_reference', 'open_key', 'expires_at', 'cancelled_by',
        'cancelled_at', 'cancel_reason', 'execution_started_at', 'execution_attempts', 'last_error'];
    protected $casts = ['payload' => 'array', 'result' => 'array', 'decided_at' => 'datetime', 'executed_at' => 'datetime', 'expires_at' => 'datetime',
        'cancelled_at' => 'datetime', 'execution_started_at' => 'datetime', 'amount_usd' => 'decimal:2'];

    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider()   { return $this->belongsTo(User::class, 'decided_by'); }
    public function target()    { return $this->belongsTo(User::class, 'target_user_id'); }
    public function events()    { return $this->hasMany(FinancialApprovalEvent::class, 'approval_request_id')->orderBy('id'); }
}
