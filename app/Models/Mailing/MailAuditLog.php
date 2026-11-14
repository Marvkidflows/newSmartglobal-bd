<?php
// TASK 3 — administrative mailing audit entry (append-only).
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailAuditLog extends Model
{
    public $timestamps = false;
    protected $table = 'mail_audit_logs';
    protected $fillable = ['actor_id', 'action', 'environment', 'subject_type', 'subject_id', 'metadata', 'ip', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
