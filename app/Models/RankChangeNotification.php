<?php
// LOCATION: app/Models/RankChangeNotification.php
// Delivery state of the admin alert for one automatic upgrade.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankChangeNotification extends Model
{
    protected $fillable = ['investor_rank_change_id', 'status', 'attempts', 'last_error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime', 'attempts' => 'integer'];

    public function change()
    {
        return $this->belongsTo(InvestorRankChange::class, 'investor_rank_change_id');
    }
}
