<?php
// LOCATION: app/Http/Controllers/Registration/RegistrationDashboardController.php
//
// Task 2 — monitoring dashboard + date-based reporting. Reads ONLY registration_* tables. No financial data is queried or returned.

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\RegistrationApplication as Application;
use App\Models\RegistrationEvent;
use App\Models\RegistrationVisit;
use App\Services\Registration\RegistrationPresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RegistrationDashboardController extends Controller
{
    // GET /api/registration/dashboard?from=YYYY-MM-DD&to=YYYY-MM-DD
    public function summary(Request $request)
    {
        Gate::authorize('registration.review');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to   = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Choose a date range of at most one year.'], 422);
        }
        $range = [$from, $to];

        $statusCounts = Application::query()->select('review_status', DB::raw('COUNT(*) as c'))->groupBy('review_status')->pluck('c', 'review_status');
        $statuses = [];
        foreach (Application::STATUSES as $s) {
            $statuses[$s] = ['label' => Application::LABELS[$s], 'count' => (int) ($statusCounts[$s] ?? 0)];
        }

        $cutoff = now()->subHours((int) config('registration.incomplete_after_hours'));
        $attemptTypes = ['application_started', 'application_resumed', 'attempt_blocked_duplicate'];

        $period = [
            'visitors'              => RegistrationVisit::whereBetween('opened_at', $range)->count(),
            'attempts'              => RegistrationEvent::whereIn('event_type', $attemptTypes)->whereBetween('created_at', $range)->count(),
            'applications_started'  => Application::whereBetween('started_at', $range)->count(),
            'registration_completed'=> Application::whereBetween('details_completed_at', $range)->count(),
            'submitted'             => Application::whereBetween('submitted_at', $range)->count(),
            'approved'              => Application::where('review_status', Application::APPROVED)->whereBetween('decided_at', $range)->count(),
            'rejected'              => Application::where('review_status', Application::REJECTED)->whereBetween('decided_at', $range)->count(),
        ];

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // The UI explains an empty "visitors" figure when tracking is disabled, rather than implying nobody visited.
            'visit_tracking_enabled' => (bool) config('registration.visit_tracking_enabled'),
            'period'   => $period,
            'current'  => [
                'statuses'          => $statuses,
                'awaiting_review'   => $statuses[Application::SUBMITTED]['count'],
                'incomplete'        => $statuses[Application::PENDING]['count'],
                'incomplete_overdue'=> Application::where('review_status', Application::PENDING)->where('last_activity_at', '<=', $cutoff)->count(),
                'unassigned_in_queue'=> Application::whereIn('review_status', [Application::SUBMITTED, Application::UNDER_REVIEW])->whereNull('assigned_to')->count(),
                'total'             => (int) $statusCounts->sum(),
            ],
            'daily'    => $this->daily($from, $to),
            'recent_activity' => RegistrationEvent::with('actor:id,name')->latest('created_at')->latest('id')->limit(15)->get()->map(fn ($e) => RegistrationPresenter::event($e))->values(),
        ]);
    }

    /** One row per day with started / submitted / approved / rejected counts (DATE() works on both MySQL and SQLite). */
    private function daily(Carbon $from, Carbon $to): array
    {
        $series = fn (string $col, ?string $status = null) => Application::query()
            ->selectRaw("DATE($col) as d, COUNT(*) as c")
            ->whereBetween($col, [$from, $to])
            ->when($status, fn ($q) => $q->where('review_status', $status))
            ->groupBy('d')->pluck('c', 'd');

        $started   = $series('started_at');
        $submitted = $series('submitted_at');
        $approved  = $series('decided_at', Application::APPROVED);
        $rejected  = $series('decided_at', Application::REJECTED);

        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $k = $d->toDateString();
            $out[] = [
                'date'      => $k,
                'started'   => (int) ($started[$k] ?? 0),
                'submitted' => (int) ($submitted[$k] ?? 0),
                'approved'  => (int) ($approved[$k] ?? 0),
                'rejected'  => (int) ($rejected[$k] ?? 0),
            ];
        }
        return $out;
    }

    // GET /api/registration/activity — the secure activity record (all events), filterable and paginated.
    public function activity(Request $request)
    {
        Gate::authorize('registration.review');

        $request->validate([
            'type'           => ['nullable', 'string', 'max:48'],
            'application_id' => ['nullable', 'integer'],
            'actor_id'       => ['nullable', 'integer'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = RegistrationEvent::with('actor:id,name')->latest('created_at')->latest('id')
            ->when($request->filled('type'), fn ($q) => $q->where('event_type', $request->query('type')))
            ->when($request->filled('application_id'), fn ($q) => $q->where('application_id', (int) $request->query('application_id')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', (int) $request->query('actor_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay()));

        $page = $q->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($e) => RegistrationPresenter::event($e))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
