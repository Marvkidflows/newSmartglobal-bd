<?php
// LOCATION: app/Models/RankAdditionalThreshold.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankAdditionalThreshold extends Model
{
    protected $table = 'rank_additional_thresholds';

    protected $guarded = ['*'];

    protected $casts = ['value' => 'float'];

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }
}
