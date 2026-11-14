<?php
// LOCATION: app/Models/FinancialAuditLog.php
//
// Append-only audit record for sensitive Financial Team actions.
// Update and delete are blocked at the model level, and no controller
// exposes any write path to this table — history cannot be edited
// through the normal application.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialAuditLog extends Model
{
    protected $fillable = [
        'reference', 'actor_id', 'actor_name', 'actor_role', 'department',
        'action', 'entity_type', 'entity_id', 'investor_id',
        'previous_value', 'new_value', 'reason',
    ];

    protected $casts = [
        'previous_value' => 'array',
        'new_value'      => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Financial audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Financial audit records cannot be deleted.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    // Shape returned by every audit endpoint (read-only views).
    public function present(): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'action'         => $this->action,
            'entity_type'    => $this->entity_type,
            'entity_id'      => $this->entity_id,
            'investor_id'    => $this->investor_id,
            'investor_name'  => $this->relationLoaded('investor') ? ($this->investor->name ?? null) : null,
            'previous_value' => $this->previous_value,
            'new_value'      => $this->new_value,
            'reason'         => $this->reason,
            'actor_name'     => $this->actor_name,
            'actor_role'     => $this->actor_role,
            'department'     => $this->department,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
