<?php
// TASK 3 — audit trail for administrative mailing actions. Metadata is scrubbed so it can never carry bodies, passwords or tokens.
namespace App\Services\Mailing;

use App\Models\Mailing\MailAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MailingAudit
{
    protected const BLOCKED_KEYS = '/password|secret|token|api_?key|credential|body|html|signature/i';

    public static function record(?User $actor, string $action, ?string $environment = null, $subject = null, array $meta = [], ?Request $request = null): MailAuditLog
    {
        $row = MailAuditLog::create([
            'actor_id'     => $actor?->id,
            'action'       => $action,
            'environment'  => $environment,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->id,
            'metadata'     => self::scrub($meta),
            'ip'           => $request?->ip(),
            'created_at'   => now(),
        ]);
        Log::info('mailing.audit', ['action' => $action, 'environment' => $environment, 'actor_id' => $actor?->id, 'subject' => $row->subject_type . '#' . $row->subject_id]);
        return $row;
    }

    public static function scrub(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            if (preg_match(self::BLOCKED_KEYS, (string) $k)) continue;
            $out[$k] = is_array($v) ? self::scrub($v) : (is_string($v) ? mb_substr($v, 0, 200) : $v);
        }
        return $out;
    }
}
