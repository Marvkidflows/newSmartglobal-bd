<?php
// TASK 3 — the single communication record for both environments (incoming and outgoing).
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailMessage extends Model
{
    protected $table = 'mail_messages';
    protected $guarded = ['id'];
    // The body is private message content: it is only serialised by controllers that explicitly opt in.
    protected $hidden = ['body_html'];
    protected $casts = [
        'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime',
    ];

    public function campaign()   { return $this->belongsTo(MailCampaign::class, 'campaign_id'); }
    public function department() { return $this->belongsTo(MailDepartment::class, 'department_id'); }
    public function mailbox()    { return $this->belongsTo(MailMailbox::class, 'mailbox_id'); }
    public function user()       { return $this->belongsTo(User::class, 'user_id'); }
    public function assignee()   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function sender()     { return $this->belongsTo(User::class, 'sender_user_id'); }
}
