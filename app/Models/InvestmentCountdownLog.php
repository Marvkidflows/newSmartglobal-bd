<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentCountdownLog extends Model
{
    protected $fillable = [
        'investment_account_id', 'admin_id', 'previous_end_date',
        'new_end_date', 'action', 'days_changed', 'reason',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'new_end_date'      => 'date',
    ];

    public function investmentAccount()
    {
        return $this->belongsTo(InvestmentAccount::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}