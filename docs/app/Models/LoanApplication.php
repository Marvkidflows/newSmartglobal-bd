<?php
// LOCATION: app/Models/LoanApplication.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanApplication extends Model
{
    public const OPEN_STATUSES = ['submitted', 'under_review'];
    public const STATUSES = ['submitted', 'under_review', 'approved', 'rejected', 'withdrawn'];

    protected $fillable = [
        'reference', 'user_id', 'rank_id_at_application', 'requested_amount', 'purpose', 'status', 'approved_terms',
        'decision_reason', 'decided_by', 'decided_by_name', 'decided_at',
    ];
    protected $casts = ['requested_amount' => 'decimal:2', 'decided_at' => 'datetime'];

    public function investor() { return $this->belongsTo(User::class, 'user_id'); }
    public function rank()     { return $this->belongsTo(InvestorRank::class, 'rank_id_at_application'); }
    public function events()   { return $this->hasMany(LoanApplicationEvent::class)->orderBy('id'); }
}
