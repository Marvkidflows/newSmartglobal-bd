<?php
// TASK 4 — append-only audit row.
namespace App\Models\News;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NewsAuditLog extends Model
{
    public $timestamps = false;
    protected $table = 'news_audit_logs';
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
