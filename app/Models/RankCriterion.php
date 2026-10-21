<?php
// LOCATION: app/Models/RankCriterion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RankCriterion extends Model
{
    use SoftDeletes;

    protected $table = 'rank_criteria';

    protected $fillable = [
        'investor_rank_id', 'name', 'description', 'logic', 'is_active', 'version',
        'created_by', 'updated_by', 'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'version'        => 'integer',
        'activated_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function rank()
    {
        return $this->belongsTo(InvestorRank::class, 'investor_rank_id');
    }

    public function conditions()
    {
        return $this->hasMany(RankCriterionCondition::class)->orderBy('group_no')->orderBy('position');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Hash of the rule's actual CONTENT (logic + conditions). Renaming a rule or editing its
     * description does not change it; changing a threshold, operator, field or logic does.
     * Used to decide whether a downgrade hold still applies.
     */
    public function ruleHash(): string
    {
        $canon = fn ($v) => is_array($v)
            ? array_map(fn ($x) => is_numeric($x) ? (string) (float) $x : $x, $v)
            : (is_numeric($v) ? (string) (float) $v : $v);

        $rows = $this->conditions->map(fn ($c) => [(int) $c->group_no, $c->field, $c->operator, $canon($c->value)])
            ->sortBy(fn ($r) => json_encode($r))->values()->all();

        return sha1(json_encode([$this->logic, $rows]));
    }

    /** Plain-array rule consumed by RankRuleEvaluator / RankCriteriaValidator. */
    public function toRule(): array
    {
        return [
            'logic'      => $this->logic,
            'conditions' => $this->conditions->map(fn ($c) => [
                'group_no' => (int) $c->group_no,
                'field'    => $c->field,
                'operator' => $c->operator,
                'value'    => $c->value,
            ])->values()->all(),
        ];
    }
}
