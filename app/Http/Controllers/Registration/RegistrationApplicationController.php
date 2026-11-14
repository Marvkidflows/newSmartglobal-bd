<?php
// LOCATION: app/Http/Controllers/Registration/RegistrationApplicationController.php
//
// Task 2 — application list, KYC review detail, and reviewer actions. Authorisation: Gate::authorize() on every action in addition to the
// 'registration.team' route middleware; approvals/rejections/assignment additionally require 'registration.decide'.

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\RegistrationApplication as Application;
use App\Models\RegistrationEvent;
use App\Models\User;
use App\Services\Registration\RegistrationCommunicationService;
use App\Services\Registration\RegistrationPresenter;
use App\Services\Registration\RegistrationReviewService;
use App\Services\Registration\RegistrationRuleException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RegistrationApplicationController extends Controller
{
    public function __construct(
        private RegistrationReviewService $review,
        private RegistrationCommunicationService $comms,
    ) {}

    private const SORTS = ['started_at', 'submitted_at', 'kyc_submitted_at', 'last_activity_at', 'decided_at'];

    // GET /api/registration/applications
    public function index(Request $request)
    {
        Gate::authorize('registration.review');
        $page = $this->applicationQuery($request)->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => RegistrationPresenter::summary($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Shared by the list and the export so both apply exactly the same validation and filters. */
    private function applicationQuery(Request $request)
    {
        $request->validate([
            'status'     => ['nullable', 'string', 'max:200'],
            'search'     => ['nullable', 'string', 'max:100'],
            'date_field' => ['nullable', Rule::in(self::SORTS)],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
            'sort'       => ['nullable', Rule::in(self::SORTS)],
            'dir'        => ['nullable', Rule::in(['asc', 'desc'])],
            'assigned'   => ['nullable', 'string', 'max:20'],
            'kyc_docs'   => ['nullable', Rule::in(['yes', 'no'])],
            'incomplete' => ['nullable', 'boolean'],
            'overdue'    => ['nullable', 'boolean'],
            'stage'      => ['nullable', 'integer', 'between:1,4'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $q = Application::query()->with([
            'user:' . implode(',', RegistrationPresenter::APPLICANT_COLUMNS),
            'assignee:id,name',
        ]);

        if ($request->filled('status')) {
            $statuses = array_values(array_intersect(explode(',', (string) $request->query('status')), Application::STATUSES));
            $q->whereIn('review_status', $statuses ?: ['__none__']);
        }

        if ($term = trim(str_replace(['%', '_', '\\'], '', (string) $request->query('search', '')))) {
            $like = "%{$term}%";
            $q->where(function ($w) use ($like, $term) {
                $w->whereHas('user', fn ($u) => $u->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)));
                if (ctype_digit($term)) $w->orWhere('id', (int) $term);
            });
        }

        $field = $request->query('date_field', 'started_at');
        if ($request->filled('from')) $q->where($field, '>=', Carbon::parse($request->query('from'))->startOfDay());
        if ($request->filled('to'))   $q->where($field, '<=', Carbon::parse($request->query('to'))->endOfDay());

        if ($request->filled('stage')) $q->where('stage_reached', (int) $request->query('stage'));

        if ($request->boolean('incomplete')) $q->where('review_status', Application::PENDING);
        if ($request->boolean('overdue')) {
            $q->where('review_status', Application::PENDING)
              ->where('last_activity_at', '<=', now()->subHours((int) config('registration.incomplete_after_hours')));
        }

        $assigned = $request->query('assigned');
        if ($assigned === 'me') $q->where('assigned_to', $request->user()->id);
        elseif ($assigned === 'unassigned') $q->whereNull('assigned_to');
        elseif ($assigned !== null && ctype_digit((string) $assigned)) $q->where('assigned_to', (int) $assigned);

        if ($request->query('kyc_docs') === 'yes') {
            $q->whereHas('user', fn ($u) => $u->whereNotNull('id_document_path')->where('id_document_path', '!=', ''));
        } elseif ($request->query('kyc_docs') === 'no') {
            $q->whereHas('user', fn ($u) => $u->where(fn ($x) => $x->whereNull('id_document_path')->orWhere('id_document_path', '')));
        }

        $sort = $request->query('sort', 'last_activity_at');
        $q->orderBy($sort, $request->query('dir', 'desc'))->orderByDesc('id');

        return $q;
    }

    private const EXPORT_MAX_ROWS = 5000;

    // GET /api/registration/applications/export — CSV of the CURRENT filters. Lead/Admin only ('registration.decide'): a bulk list of applicants' personal
    // data is a bigger disclosure than viewing one application. Contains NO ID numbers, document/selfie links, phone numbers or financial data.
    // Capped, throttled (route), formula-injection-safe, and recorded in the append-only activity history.
    public function export(Request $request)
    {
        Gate::authorize('registration.decide');
        $rows = $this->applicationQuery($request)->limit(self::EXPORT_MAX_ROWS)->get();
        $actor = $request->user();
        RegistrationEvent::record('report_exported', null, $actor, null, null, null, ['rows' => $rows->count(), 'filters' => $request->only(['status', 'search', 'date_field', 'from', 'to', 'assigned', 'kyc_docs', 'incomplete', 'overdue', 'stage'])]);

        $cell = function ($v): string {
            $v = (string) ($v ?? '');
            $v = str_replace(["\r", "\n"], ' ', $v);
            if ($v !== '' && strpbrk($v[0], "=+-@\t") !== false) $v = "'" . $v;   // spreadsheet formula injection
            return '"' . str_replace('"', '""', $v) . '"';
        };
        $iso = fn ($d) => $d ? $d->toIso8601String() : '';
        $head = ['application_id', 'status', 'stage_reached', 'applicant_name', 'applicant_email', 'country', 'started_at', 'submitted_at', 'kyc_submitted_at', 'decided_at', 'last_activity_at', 'assigned_to', 'kyc_document_submitted'];
        $lines = [implode(',', $head)];
        foreach ($rows as $a) {
            $u = $a->user;
            $lines[] = implode(',', array_map($cell, [
                $a->id, $a->review_status, $a->stage_reached, $u?->full_name ?: $u?->name, $u?->email, $u?->country,
                $iso($a->started_at), $iso($a->submitted_at), $iso($a->kyc_submitted_at), $iso($a->decided_at), $iso($a->last_activity_at),
                $a->assignee?->name, ($u && $u->id_document_path) ? 'yes' : 'no',
            ]));
        }
        return response("\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="registration-applications-' . now()->format('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // GET /api/registration/applications/{application}
    public function show(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $actor = $request->user();
        $application->load(['user', 'assignee:id,name']);

        // Audit who looked at identity documents (debounced to one entry per reviewer per application per 30 minutes).
        if ($application->user->id_document_path) {
            $recent = RegistrationEvent::where('application_id', $application->id)->where('event_type', 'kyc_viewed')
                ->where('actor_id', $actor->id)->where('created_at', '>=', now()->subMinutes(30))->exists();
            if (!$recent) RegistrationEvent::record('kyc_viewed', $application, $actor);
        }

        $events = $application->events()->with('actor:id,name')->latest('created_at')->latest('id')->limit(200)->get();
        $comms  = $application->communications()->with('sender:id,name')->latest('id')->limit(50)->get();

        return response()->json([
            'application' => RegistrationPresenter::detail(
                $application, $actor,
                $this->review->allowedTransitions($application, $actor),
                $this->comms->followUpBlocker($application),
            ),
            'timeline' => $events->map(fn ($e) => RegistrationPresenter::event($e))->values(),
            'communications' => $comms->map(fn ($c) => [
                'id' => $c->id, 'kind' => $c->kind, 'subject' => $c->subject, 'body' => $c->body, 'to_email' => $c->to_email,
                'status' => $c->status, 'error' => $c->error, 'sent_at' => optional($c->sent_at)->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
                'sent_by' => $c->sender ? ['id' => $c->sender->id, 'name' => $c->sender->name] : null,
            ])->values(),
        ]);
    }

    // POST /api/registration/applications/{application}/status   { status, note? }
    public function status(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate([
            'status' => ['required', Rule::in(Application::STATUSES)],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);
        if (in_array($data['status'], Application::DECISION_STATUSES, true)) {
            Gate::authorize('registration.decide');
        }

        return $this->run(fn () => $this->review->transition($application, $request->user(), $data['status'], $data['note'] ?? null), 'Status updated.');
    }

    // POST /api/registration/applications/{application}/notes   { note }
    public function note(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->run(function () use ($application, $request, $data) {
            $this->review->addNote($application, $request->user(), $data['note']);
            return $application->fresh();
        }, 'Note recorded.');
    }

    // POST /api/registration/applications/{application}/request-info   { message }
    public function requestInfo(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate(['message' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->run(
            fn () => $this->review->transition($application, $request->user(), Application::ADDITIONAL_INFO_REQUIRED, $data['message']),
            'Additional information requested. The applicant has been notified.'
        );
    }

    // POST /api/registration/applications/{application}/follow-up   { message, subject? }
    public function followUp(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:150', 'not_regex:/[\r\n]/'],   // no line breaks: blocks email-header injection
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return $this->run(function () use ($application, $request, $data) {
            $comm = $this->comms->sendFollowUp($application, $request->user(), $data['subject'] ?? 'Completing your SSI registration', $data['message']);
            if ($comm->status === 'failed') {
                throw new RegistrationRuleException('The message could not be delivered. It has been logged as failed; check the mail configuration or try again later.');
            }
            return $application->fresh();
        }, 'Follow-up sent.');
    }

    // POST /api/registration/applications/{application}/assign   { assignee_id|null }
    public function assign(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate(['assignee_id' => ['nullable', 'integer', 'exists:users,id']]);
        $assignee = isset($data['assignee_id']) ? User::find($data['assignee_id']) : null;

        return $this->run(fn () => $this->review->assign($application, $request->user(), $assignee), 'Assignment updated.');
    }

    // POST /api/registration/applications/{application}/follow-up-opt-out   { opted_out, note? }
    public function followUpOptOut(Request $request, Application $application)
    {
        Gate::authorize('registration.review');
        $data = $request->validate(['opted_out' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->run(fn () => $this->review->setFollowUpOptOut($application, $request->user(), (bool) $data['opted_out'], $data['note'] ?? null), 'Preference recorded.');
    }

    // GET /api/registration/team — active team members, for the assignment picker (names only).
    public function team(Request $request)
    {
        Gate::authorize('registration.review');
        $members = User::whereIn('role', ['registration_member', 'registration_lead'])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'role']);
        return response()->json(['team' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->role])->values()]);
    }

    /** Runs a service call, mapping business-rule refusals to 422 and returning the refreshed application summary. */
    private function run(callable $action, string $message)
    {
        try {
            $application = $action();
        } catch (RegistrationRuleException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $application->load(['user:' . implode(',', RegistrationPresenter::APPLICANT_COLUMNS), 'assignee:id,name']);
        return response()->json(['success' => true, 'message' => $message, 'application' => RegistrationPresenter::summary($application)]);
    }
}
