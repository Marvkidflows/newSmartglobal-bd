<?php
// TASK 3 — an individual staff (or role) mailbox. Holds NO password, token or key: only a reference NAME to a secret kept in the
// environment / secret manager. connection_status is only ever 'connected' after MailboxService::checkConnection() has confirmed it
// with the provider; creating a row never claims a working mailbox.
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailMailbox extends Model
{
    protected $table = 'mail_mailboxes';
    protected $fillable = ['department_id', 'user_id', 'address', 'display_name', 'provider', 'credential_ref', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sender_verified_at' => 'datetime', 'last_checked_at' => 'datetime'];

    public function department() { return $this->belongsTo(MailDepartment::class, 'department_id'); }
    public function user()       { return $this->belongsTo(User::class); }
}
