<?php
// LOCATION: app/Models/ProfitAdjustment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfitAdjustment extends Model
{
    protected $fillable = [
        'admin_id',
        'scope',
        'sector_id',
        'investment_plan_id',
        'percentage_change',
        'affected_count',
        'reason',
        'status', 'approval_request_id', 'approved_by', 'authorization_reference', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'percentage_change' => 'decimal:2',
        'reversed_at'       => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function investmentPlan()
    {
        return $this->belongsTo(InvestmentPlan::class);
    }
}