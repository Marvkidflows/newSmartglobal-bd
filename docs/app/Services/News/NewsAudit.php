<?php
// TASK 4 — audit trail (no article bodies, no secrets in metadata).
namespace App\Services\News;

use App\Models\News\NewsAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class NewsAudit
{
    public static function record(?User $actor, string $action, $subject = null, array $meta = [], ?Request $req = null): void
    {
        $clean = [];
        foreach ($meta as $k => $v) {
            if (preg_match('/password|secret|token|body|html|key/i', (string) $k)) continue;
            $clean[$k] = is_string($v) ? mb_substr($v, 0, 200) : $v;
        }
        NewsAuditLog::create([
            'actor_id' => $actor?->id, 'action' => $action, 'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->id, 'metadata' => $clean, 'ip' => $req?->ip(), 'created_at' => now(),
        ]);
    }
}
