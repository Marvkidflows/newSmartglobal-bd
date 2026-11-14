<?php
// LOCATION: app/Models/InvestorConsentLog.php
// Append-only log of an investor's consent decisions. The CURRENT state is the newest row for (user, purpose).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvestorConsentLog extends Model
{
    public const RECOGNITION = 'recognition_communications';

    protected $table = 'investor_consent_log';
    public $timestamps = false;
    protected $fillable = ['user_id', 'purpose', 'granted', 'created_at'];
    protected $casts = ['granted' => 'boolean', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent records are immutable; record a new decision instead.'));
        static::deleting(fn () => throw new LogicException('Consent records cannot be deleted.'));
    }
}
