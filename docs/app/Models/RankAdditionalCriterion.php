<?php
// LOCATION: app/Models/RankAdditionalCriterion.php
// One of the five optional criteria. NOT mass-assignable from requests; written only by RankAdditionalCriteriaService.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankAdditionalCriterion extends Model
{
    protected $table = 'rank_additional_criteria';

    protected $guarded = ['*'];

    protected $casts = [
        'enabled' => 'boolean', 'is_monetary' => 'boolean', 'policy' => 'array',
        'policy_set_at' => 'datetime', 'enabled_at' => 'datetime',
    ];

    public function thresholds()
    {
        return $this->hasMany(RankAdditionalThreshold::class, 'criterion_key', 'key');
    }
}
