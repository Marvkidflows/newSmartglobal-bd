<?php
// TASK 3 — shared helpers for the mailing controllers.
namespace App\Http\Controllers\Mailing;

use App\Models\Mailing\MailMessage;
use App\Models\User;
use App\Services\Mailing\MailHeaderGuard;
use App\Services\Mailing\MailingPermissions;
use Illuminate\Http\Request;

trait Concerns
{
    /** Validation closure: reject header-injection attempts outright (422) instead of silently altering the value. */
    protected function noInjection(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (is_string($value) && MailHeaderGuard::hasInjection($value)) $fail("The {$attribute} contains illegal line-break characters.");
        };
    }

    protected function messageRow(MailMessage $m, bool $withBody = false): array
    {
        $row = [
            'id' => $m->id, 'environment' => $m->environment, 'direction' => $m->direction, 'kind' => $m->kind, 'status' => $m->status,
            'skip_reason' => $m->skip_reason, 'subject' => $m->subject, 'recipient_email' => $m->recipient_email, 'recipient_name' => $m->recipient_name,
            'from_address' => $m->from_address, 'department' => $m->department?->name, 'department_id' => $m->department_id, 'campaign_id' => $m->campaign_id,
            'assigned_to' => $m->assigned_to, 'assignee' => $m->assignee?->name, 'sender' => $m->sender?->name, 'attempts' => $m->attempts,
            'error_code' => $m->error_code, 'error_message' => $m->error_message,
            'queued_at' => $m->queued_at, 'sent_at' => $m->sent_at, 'delivered_at' => $m->delivered_at, 'failed_at' => $m->failed_at, 'created_at' => $m->created_at,
        ];
        if ($withBody) $row['body_html'] = $m->body_html;
        return $row;
    }

    /** May this staff member read this message? Environment-wide view permission, or membership of the owning department. */
    protected function canSeeMessage(?User $u, MailMessage $m): bool
    {
        // Integration (Task 2 x Task 3): the Registration Team handles applicants (public environment) and never sees investor correspondence,
        // even if it was mistakenly added to an investor department.
        if ($u && in_array($u->role, MailingPermissions::REGISTRATION_ROLES, true) && $m->environment !== 'public') return false;
        return MailingPermissions::can($u, $m->environment . '.view') || MailingPermissions::inDepartment($u, $m->department_id);
    }

    protected function envOr404(Request $r): string
    {
        $env = (string) $r->route('environment');
        abort_unless(in_array($env, ['public', 'investor'], true), 404);
        return $env;
    }
}
