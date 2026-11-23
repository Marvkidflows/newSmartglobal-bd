<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\FinancialAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff inbox for PUBLIC contact-support requests (POST /public/contact-support). Those requests are already persisted in `messages`
 * (initiated_by=investor, subject below) and are only ever created for an existing investor account; no e-mail destination is involved.
 * This controller adds list/detail, search, read and open/closed state, with an append-only audit row for every state change.
 * ADMIN ONLY (route group). The list masks the e-mail address; the full address is shown only in the detail view.
 */
class SupportRequestController extends Controller
{
    public const SUBJECT = 'Account Deactivated — Support Request';

    /** Production runs migrations only through the separate `migrate` deployment job. Until it has run, say so plainly instead of a bare 500. */
    private function schemaMissing()
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('support_request_states')) return null;
        return response()->json(['message' => 'Support Requests needs the latest database migration (table support_request_states is missing). Run the migrate deployment job, then reload this page.', 'code' => 'migration_pending'], 503);
    }

    private function base()
    {
        return Message::query()->where('initiated_by', 'investor')->where('subject', self::SUBJECT);
    }

    public static function maskEmail(?string $e): string
    {
        if (!$e || !str_contains($e, '@')) return '';
        [$l, $d] = explode('@', $e, 2);
        return mb_substr($l, 0, 1) . str_repeat('*', max(2, mb_strlen($l) - 1)) . '@' . $d;
    }

    private function state(Message $m): string
    {
        return DB::table('support_request_states')->where('message_id', $m->id)->value('status') ?? 'open';
    }

    // GET /admin/support-requests?status=&read=&q=&page=&per_page=
    public function index(Request $request)
    {
        if ($r = $this->schemaMissing()) return $r;
        $v = $request->validate(['status' => ['nullable', 'in:open,closed'], 'read' => ['nullable', 'in:read,unread'], 'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $q = $this->base()->with('investor:id,name,email')
            ->leftJoin('support_request_states as s', 's.message_id', '=', 'messages.id')->select('messages.*', DB::raw("COALESCE(s.status,'open') as request_status"));
        if (($v['status'] ?? null) === 'closed') $q->where('s.status', 'closed');
        if (($v['status'] ?? null) === 'open') $q->where(fn ($w) => $w->whereNull('s.status')->orWhere('s.status', 'open'));
        if (($v['read'] ?? null) === 'unread') $q->where('read_by_admin', false);
        if (($v['read'] ?? null) === 'read') $q->where('read_by_admin', true);
        if (!empty($v['q'])) {
            // '!' is the LIKE escape character: portable across MySQL and SQLite (SQLite has no default escape).
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v['q']) . '%';
            $q->where(fn ($w) => $w->whereRaw("messages.body LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('investor', fn ($i) => $i->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("email LIKE ? ESCAPE '!'", [$like])));
        }
        $p = $q->orderByDesc('messages.id')->paginate($v['per_page'] ?? 25);
        return response()->json([
            'requests' => collect($p->items())->map(fn ($m) => [
                'id' => $m->id, 'investor_id' => $m->investor_id, 'investor' => $m->investor?->name, 'email_masked' => self::maskEmail($m->investor?->email),
                'snippet' => mb_substr($m->body, 0, 120, 'UTF-8') . (mb_strlen($m->body, 'UTF-8') > 120 ? '…' : ''),
                'status' => $m->request_status, 'read' => (bool) $m->read_by_admin, 'received_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
            'note' => 'Requests submitted through the public contact-support form by existing investor accounts. E-mail addresses are masked in this list.',
        ]);
    }

    // GET /admin/support-requests/{id}  — read-only (does not mark as read)
    public function show(int $id)
    {
        if ($r = $this->schemaMissing()) return $r;
        $m = $this->base()->with('investor:id,name,email,status')->findOrFail($id);
        return response()->json(['request' => [
            'id' => $m->id, 'investor_id' => $m->investor_id, 'investor' => $m->investor?->name, 'email' => $m->investor?->email, 'account_status' => $m->investor?->status,
            'body' => $m->body, 'status' => $this->state($m), 'read' => (bool) $m->read_by_admin, 'received_at' => $m->created_at?->toIso8601String(),
        ]]);
    }

    // PATCH /admin/support-requests/{id}   body: {status?: open|closed, read?: bool, note?: string}
    public function update(Request $request, int $id, FinancialAuditService $audit)
    {
        if ($r = $this->schemaMissing()) return $r;
        $v = $request->validate(['status' => ['nullable', 'in:open,closed'], 'read' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        if (!array_key_exists('status', $v) && !array_key_exists('read', $v)) return response()->json(['message' => 'Nothing to change.'], 422);
        $actor = $request->user();
        return DB::transaction(function () use ($id, $v, $actor, $audit) {
            $m = $this->base()->lockForUpdate()->findOrFail($id);
            $prev = ['status' => $this->state($m), 'read' => (bool) $m->read_by_admin];
            if (isset($v['status']) && $v['status'] !== $prev['status']) {
                DB::table('support_request_states')->updateOrInsert(['message_id' => $m->id], ['status' => $v['status'], 'updated_by' => $actor->id, 'updated_by_name' => $actor->name ?? 'Admin', 'updated_at' => now(), 'created_at' => now()]);
            }
            if (isset($v['read']) && (bool) $v['read'] !== $prev['read']) $m->forceFill(['read_by_admin' => (bool) $v['read']])->save();
            $new = ['status' => $this->state($m), 'read' => (bool) $m->fresh()->read_by_admin];
            if ($new !== $prev) $audit->record($actor, 'support_request.updated', 'support_request', $m->id, $m->investor_id, $prev, $new, $v['note'] ?? null);
            return response()->json(['request' => ['id' => $m->id] + $new]);
        });
    }
}
