<?php
// LOCATION: app/Models/ProfitAdjustmentItem.php
//
// One row per investment changed by a profit adjustment: full before/after values. Append-only except reversed_at/reversed_by.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfitAdjustmentItem extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'profit_adjustment_id', 'investment_id', 'user_id',
        'before_profit_percentage', 'before_expected_profit', 'before_total_return',
        'after_profit_percentage', 'after_expected_profit', 'after_total_return',
        'reversed_at', 'reversed_by', 'created_at',
    ];
    protected $casts = ['reversed_at' => 'datetime', 'created_at' => 'datetime'];
}
