<?php
// LOCATION: app/Models/RankDowngradeReview.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankDowngradeReview extends Model
{
    protected $fillable = [
        'investor_id', 'investor_rank_id', 'rank_criterion_id', 'criterion_version', 'fingerprint',
        'failed_conditions', 'status', 'notified_at', 'resolved_by', 'resolved_by_name',
        'resolution_reason', 'resolved_at',
    ];

    protected $casts = [
        'failed_conditions' => 'array',
        'notified_at'       => 'datetime',
        'resolved_at'       => 'datetime',
    ];

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
