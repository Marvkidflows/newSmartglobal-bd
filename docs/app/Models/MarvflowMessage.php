<?php
// LOCATION: app/Models/MarvflowMessage.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarvflowMessage extends Model
{
    public const RECIPIENTS = [
        'admin'     => 'SSI System Admin',
        'financial' => 'SSI Financial Team',
    ];

    public const DIRECT_MAX = 2000;           // quick-message length limit (characters)

    public const TO_SSI      = 'to_ssi';        // written by MarvFlow (first message or follow-up)
    public const TO_MARVFLOW = 'to_marvflow';   // written by SSI Admin / Financial Team (a reply)

    // Not mass-assignable from request data: the controller builds every attribute explicitly.
    protected $guarded = ['*'];

    protected $casts = ['read_at' => 'datetime', 'notified_count' => 'integer'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reader()
    {
        return $this->belongsTo(User::class, 'read_by');
    }
}
