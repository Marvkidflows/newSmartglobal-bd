<?php
// LOCATION: app/Models/FinancialApprovalEvent.php  — append-only audit history of an approval request.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialApprovalEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['approval_request_id', 'event', 'actor_id', 'actor_role', 'from_status', 'to_status', 'note', 'meta', 'created_at'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approval events are append-only.'));
        static::deleting(fn () => throw new LogicException('Approval events are append-only.'));
    }

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
