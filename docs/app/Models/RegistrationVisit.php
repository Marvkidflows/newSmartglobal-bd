<?php
// LOCATION: app/Models/RegistrationVisit.php
// Consented, pseudonymous record of a visitor opening the registration page. Only a SHA-256 hash of a client-generated random token is
// stored — never an IP address, user-agent or the raw token.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationVisit extends Model
{
    protected $fillable = ['token_hash', 'consent_notice_version', 'user_id', 'page_views', 'opened_at', 'last_seen_at', 'attempted_at'];

    protected $casts = [
        'opened_at'    => 'datetime',
        'last_seen_at' => 'datetime',
        'attempted_at' => 'datetime',
    ];

    public static function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
