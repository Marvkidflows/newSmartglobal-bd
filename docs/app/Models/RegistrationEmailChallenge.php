<?php
// LOCATION: app/Models/RegistrationEmailChallenge.php
// One-time ownership code for a NEW registration (no account exists yet). Stores only an HMAC of the code.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationEmailChallenge extends Model
{
    public $timestamps = false;

    protected $fillable = ['email', 'code_hash', 'attempts', 'delivery_status', 'expires_at', 'consumed_at', 'created_at'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT    = 'sent';
    public const DELIVERY_FAILED  = 'failed';

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
