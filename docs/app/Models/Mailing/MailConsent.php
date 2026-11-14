<?php
// TASK 3 — append-only consent ledger row (timestamp + purpose + source). Never updated or deleted by application code.
namespace App\Models\Mailing;

use Illuminate\Database\Eloquent\Model;

class MailConsent extends Model
{
    public $timestamps = false;
    protected $table = 'mail_consents';
    protected $fillable = ['email', 'contact_id', 'user_id', 'purpose', 'action', 'source', 'policy_version', 'ip_hash', 'user_agent', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
