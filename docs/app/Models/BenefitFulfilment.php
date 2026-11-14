<?php
// LOCATION: app/Models/BenefitFulfilment.php — see the migration for the purpose. Status changes go through BenefitFulfilmentService only.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BenefitFulfilment extends Model
{
    public const REQUESTED = 'requested';
    public const ASSIGNED = 'assigned';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::REQUESTED, self::ASSIGNED, self::IN_PROGRESS];

    protected $fillable = ['user_id', 'rank_benefit_id', 'benefit_key', 'status', 'assigned_to', 'assigned_to_name', 'requested_at', 'due_date',
        'completed_at', 'completion_reference', 'notes', 'created_by'];

    protected $casts = ['requested_at' => 'datetime', 'completed_at' => 'datetime', 'due_date' => 'date'];

    public function user() { return $this->belongsTo(User::class); }
    public function benefit() { return $this->belongsTo(RankBenefit::class, 'rank_benefit_id'); }
}
