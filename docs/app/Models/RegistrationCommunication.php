<?php
// LOCATION: app/Models/RegistrationCommunication.php
// A message the Registration Team sent (or tried to send) to an applicant, with its delivery outcome.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationCommunication extends Model
{
    public const KIND_INFO_REQUEST = 'info_request';
    public const KIND_FOLLOW_UP    = 'follow_up';
    public const KIND_STATUS       = 'status_notice';

    protected $fillable = ['application_id', 'sent_by', 'kind', 'to_email', 'subject', 'body', 'status', 'dedupe_key', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function application() { return $this->belongsTo(RegistrationApplication::class, 'application_id'); }
    public function sender()      { return $this->belongsTo(User::class, 'sent_by'); }
}
