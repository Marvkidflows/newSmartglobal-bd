<?php
// LOCATION: app/Models/RegistrationEvent.php
// APPEND-ONLY audit record. Like InvestorConsentLog, rows can never be edited or deleted through the model, so the activity history of an
// application cannot be quietly rewritten. (Rows are removed only by the database's own cascade when an application's user is erased.)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RegistrationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'application_id', 'visit_id', 'actor_id', 'actor_role', 'event_type', 'from_status', 'to_status', 'note', 'meta', 'created_at',
    ];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registration events are immutable; record a new event instead.'));
        static::deleting(fn () => throw new LogicException('Registration events cannot be deleted.'));
    }

    public function application() { return $this->belongsTo(RegistrationApplication::class, 'application_id'); }
    public function actor()       { return $this->belongsTo(User::class, 'actor_id'); }

    /** Single write path so every event is shaped the same way. */
    public static function record(
        string $type,
        ?RegistrationApplication $application = null,
        ?User $actor = null,
        ?string $from = null,
        ?string $to = null,
        ?string $note = null,
        array $meta = [],
        ?int $visitId = null,
    ): self {
        return static::create([
            'application_id' => $application?->id,
            'visit_id'       => $visitId,
            'actor_id'       => $actor?->id,
            'actor_role'     => $actor?->role,
            'event_type'     => $type,
            'from_status'    => $from,
            'to_status'      => $to,
            'note'           => $note,
            'meta'           => $meta ?: null,
            'created_at'     => now(),
        ]);
    }
}
