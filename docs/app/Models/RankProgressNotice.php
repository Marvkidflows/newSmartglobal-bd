<?php
// LOCATION: app/Models/RankProgressNotice.php
// Bookkeeping for "approaching the next rank" notices. Written only by RankProgressNoticeService; never by request input.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankProgressNotice extends Model
{
    protected $fillable = [
        'investor_id', 'next_rank_id', 'milestone', 'percent_at_notice', 'amount_percent', 'cycles_percent',
        'active', 'carried_notification', 'notification_id', 'notified_at', 'reset_at',
    ];

    protected $casts = [
        'active' => 'boolean', 'carried_notification' => 'boolean', 'notified_at' => 'datetime', 'reset_at' => 'datetime',
    ];
}
