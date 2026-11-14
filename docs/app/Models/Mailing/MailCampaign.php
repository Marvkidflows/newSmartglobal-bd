<?php
// TASK 3 — a bulk campaign (newsletter / announcement / essential notice) addressed to one environment's audience.
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailCampaign extends Model
{
    protected $table = 'mail_campaigns';
    protected $fillable = [
        'environment', 'name', 'subject', 'body_html', 'template_id', 'kind', 'purpose', 'audience_type', 'audience_filters',
        'status', 'scheduled_at', 'client_token', 'department_id', 'created_by',
    ];
    protected $casts = [
        'audience_filters' => 'array',
        'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function messages() { return $this->hasMany(MailMessage::class, 'campaign_id'); }
    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }
    public function isEditable(): bool { return in_array($this->status, ['draft', 'scheduled'], true); }
}
