<?php
// LOCATION: app/Models/RegistrationResumeChallenge.php
// One-time ownership code for resuming an unfinished registration. Stores only an HMAC of the code.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationResumeChallenge extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'code_hash', 'attempts', 'delivery_status', 'expires_at', 'consumed_at', 'created_at'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT    = 'sent';
    public const DELIVERY_FAILED  = 'failed';

    /** HMAC keyed by the application key, so a leaked table cannot be used to recover or precompute live codes. */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
