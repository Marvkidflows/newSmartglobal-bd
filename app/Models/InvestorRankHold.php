<?php
// LOCATION: app/Models/InvestorRankHold.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestorRankHold extends Model
{
    protected $fillable = [
        'investor_id', 'investor_rank_change_id', 'blocked', 'status', 'released_at', 'release_reason',
    ];

    protected $casts = ['blocked' => 'array', 'released_at' => 'datetime'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
