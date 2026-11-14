<?php
// LOCATION: app/Models/RankCriterionCondition.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankCriterionCondition extends Model
{
    protected $fillable = ['rank_criterion_id', 'group_no', 'field', 'operator', 'value', 'position'];

    protected $casts = ['value' => 'json', 'group_no' => 'integer'];

    public function criterion()
    {
        return $this->belongsTo(RankCriterion::class, 'rank_criterion_id');
    }
}
