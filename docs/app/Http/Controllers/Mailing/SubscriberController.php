<?php
// TASK 3 — subscriber and unsubscribe management. Lists expose addresses only to staff holding {env}.manage.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\Mailing\MailConsent;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailSubscription;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\MailingAudit;
use App\Services\Mailing\PublicMailingService;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    use Concerns;

    // GET /api/mailing/{environment}/subscribers?purpose=&status=&q=
    public function index(Request $r, string $environment)
    {
        $purposes = ConsentService::purposesFor($environment);
        $q = MailSubscription::whereIn('purpose', $purposes)->with('contact:id,name,status');
        if ($r->filled('purpose') && in_array($r->input('purpose'), $purposes, true)) $q->where('purpose', $r->input('purpose'));
        if ($r->filled('status')) $q->where('status', $r->string('status'));
        if ($r->filled('q')) $q->where('email', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr(strtolower(trim((string) $r->input('q'))), 0, 100)) . '%');

        $rows = $q->orderByDesc('changed_at')->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($s) => [
            'id' => $s->id, 'email' => $s->email, 'name' => $s->contact?->name, 'purpose' => $s->purpose, 'status' => $s->status,
            'contact_status' => $s->contact?->status, 'changed_at' => $s->changed_at,
        ]);
        return response()->json($rows);
    }

    // POST /api/mailing/public/subscribers — staff-initiated sign-up. Still double opt-in: staff cannot grant consent on someone's behalf.
    public function store(Request $r, PublicMailingService $public)
    {
        $d = $r->validate([
            'email' => ['required', 'email', 'max:190', $this->noInjection()], 'name' => ['nullable', 'string', 'max:120', $this->noInjection()],
            'purposes' => ['required', 'array', 'min:1'], 'purposes.*' => ['in:' . implode(',', ConsentService::PUBLIC_PURPOSES)],
        ]);
        $public->subscribe($d['email'], $d['name'] ?? null, $d['purposes'], $r);
        MailingAudit::record($r->user(), 'subscriber.invited', 'public', null, ['purposes' => $d['purposes']], $r);
        return response()->json(['success' => true, 'message' => 'A confirmation email has been queued. The person is subscribed only after confirming.'], 202);
    }

    // POST /api/mailing/{environment}/subscribers/{subscription}/unsubscribe
    public function unsubscribe(Request $r, string $environment, int $subscription, ConsentService $consent)
    {
        $subscription = MailSubscription::findOrFail($subscription);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        abort_unless(ConsentService::environmentOf($subscription->purpose) === $environment, 404);
        $contact = $subscription->contact_id ? MailContact::find($subscription->contact_id) : null;
        $user = $subscription->user_id ? User::find($subscription->user_id) : null;
        $consent->withdraw($subscription->email, $subscription->purpose, 'admin', $contact, $user, $r);
        MailingAudit::record($r->user(), 'subscriber.unsubscribed', $environment, $subscription, ['purpose' => $subscription->purpose], $r);
        return response()->json(['success' => true]);
    }

    // POST /api/mailing/public/contacts/{contact}/status  { status: active|suppressed }
    public function contactStatus(Request $r, int $contact)
    {
        $contact = MailContact::findOrFail($contact);   // looked up AFTER the permission middleware, so ids are never revealed to unauthorised callers
        $d = $r->validate(['status' => ['required', 'in:active,suppressed']]);
        $contact->update(['status' => $d['status']]);
        MailingAudit::record($r->user(), 'contact.status_changed', 'public', $contact, ['status' => $d['status']], $r);
        return response()->json(['success' => true]);
    }

    // GET /api/mailing/{environment}/unsubscribes — withdrawal history from the consent ledger
    public function unsubscribes(Request $r, string $environment)
    {
        $rows = MailConsent::whereIn('purpose', ConsentService::purposesFor($environment))->where('action', 'withdrawn')->latest('id')
            ->paginate(min(100, (int) $r->integer('per_page', 25)))->through(fn ($c) => [
                'id' => $c->id, 'email' => $c->email, 'purpose' => $c->purpose, 'source' => $c->source, 'created_at' => $c->created_at,
            ]);
        return response()->json($rows);
    }

    // GET /api/mailing/{environment}/consents?email= — consent history for one address
    public function consents(Request $r, string $environment)
    {
        $email = strtolower(trim((string) $r->query('email')));
        abort_if($email === '', 422, 'email is required');
        return response()->json(['consents' => MailConsent::where('email', $email)->whereIn('purpose', ConsentService::purposesFor($environment))->orderByDesc('id')->limit(100)
            ->get(['purpose', 'action', 'source', 'policy_version', 'created_at'])]);
    }
}
