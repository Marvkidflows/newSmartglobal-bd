<?php
// LOCATION: app/Models/RankIncentiveTerm.php
// A rank-based incentive (fixed or percentage bonus) as a CONFIGURATION RECORD. It is never applied to a payout or to a
// contractual return by this module. It cannot become `active` without the approval chain in RankIncentiveService.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankIncentiveTerm extends Model
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending_approval';
    public const APPROVED = 'approved';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    public const KINDS = ['fixed_bonus', 'percentage_bonus'];

    protected $fillable = [
        'label', 'investor_rank_id', 'investment_plan_id', 'kind', 'value', 'conditions', 'status', 'effective_from', 'effective_to',
        'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_by_name', 'approved_at', 'approval_reference',
        'activated_by', 'activated_at', 'version',
    ];

    protected $casts = [
        'value' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'submitted_at' => 'datetime',
        'approved_at' => 'datetime', 'activated_at' => 'datetime', 'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Incentive terms are disabled, never deleted.'));
    }

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }

    public function snapshot(): array
    {
        return [
            'label' => $this->label, 'rank_id' => $this->investor_rank_id, 'plan_id' => $this->investment_plan_id, 'kind' => $this->kind,
            'value' => $this->value, 'conditions' => $this->conditions, 'status' => $this->status,
            'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(),
            'approval_reference' => $this->approval_reference, 'version' => (int) $this->version,
        ];
    }
}
