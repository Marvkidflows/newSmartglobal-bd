<?php
// LOCATION: app/Models/FixtureSyncLog.php
//
// One row per FixtureSyncService run. Read-only from the application's
// perspective once written — see FixtureSyncService::runFullSync().

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureSyncLog extends Model
{
    protected $fillable = [
        'source', 'status', 'fixtures_imported', 'fixtures_updated',
        'competitions_checked', 'message', 'error', 'triggered_by',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function triggeredByUser()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
