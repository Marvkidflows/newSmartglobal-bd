<?php
// LOCATION: app/Models/RankBenefitAudit.php
// Append-only audit record for benefits, incentive terms and the lending programme.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankBenefitAudit extends Model
{
    protected $table = 'rank_benefit_audit';
    public $timestamps = false;

    protected $fillable = ['subject_type', 'subject_id', 'action', 'actor_id', 'actor_name', 'actor_role', 'previous', 'new', 'reason', 'created_at'];
    protected $casts = ['previous' => 'array', 'new' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Benefit audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Benefit audit records cannot be deleted.'));
    }
}
