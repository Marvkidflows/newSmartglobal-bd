<?php
// LOCATION: app/Services/Rank/LoanService.php
//
// Diamond / Elite LOAN APPLICATION PATHWAY (Framework §3 E–F, §7, §8). Eligibility + application tracking ONLY.
//
//  • The programme is closed until a person records a policy reference AND authorises it, and then activates it.
//  • Rank gives eligibility to APPLY. It never approves, prices, limits or disburses a loan. The system holds no
//    interest rate, limit, fee or repayment schedule; whoever decides writes the approved terms as text.
//  • Decisions require the loans.decide ability, a recorded reason, and are logged in an append-only timeline.
//  • Nothing here touches wallets, balances, investments or rank assignment.
//  • NOT built (needs an approved lending policy first): disbursement, repayment schedule, outstanding balance.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\LoanApplication;
use App\Models\LoanApplicationEvent;
use App\Models\LoanProgrammeSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(protected RankBenefitService $benefits) {}

    // ── Hard lock (Board decision §6: lending is not authorised for production) ─────────────────────────

    public const LOCK_MESSAGE = 'The lending programme is not available. It has not been authorised.';

    public static function locked(): bool
    {
        return !config('lending.enabled', false);
    }

    /** Every mutating lending path calls this first, so no configuration, application or decision can bypass the lock. */
    protected function assertUnlocked(): void
    {
        abort_if(self::locked(), 403, self::LOCK_MESSAGE);
    }

    // ── Eligibility ─────────────────────────────────────────────────────────────────────────────────────

    /** @return array{eligible:bool, reason:?string, code:string} */
    public function eligibility(User $user): array
    {
        if (self::locked()) return $this->no('lending_locked', self::LOCK_MESSAGE);

        $s = LoanProgrammeSetting::current();

        if (!$s->is_authorized) return $this->no('programme_not_authorized', 'The lending programme has not been authorized by SSI yet.');
        if (!$s->is_active)     return $this->no('programme_not_active', 'The lending programme is not open for applications at the moment.');
        if (($user->rank_status ?? 'active') !== 'active' || !$user->investor_rank_id) {
            return $this->no('no_active_rank', 'You need an active Diamond or Elite rank to apply.');
        }

        $rank = InvestorRank::find($user->investor_rank_id);
        if (!$rank || !in_array($rank->key, $s->eligible_rank_keys ?? [], true)) {
            return $this->no('rank_not_eligible', 'Loan applications are available to investors with an eligible rank.');
        }
        if (LoanApplication::where('user_id', $user->id)->whereIn('status', LoanApplication::OPEN_STATUSES)->exists()) {
            return $this->no('open_application', 'You already have an application in progress.');
        }

        return ['eligible' => true, 'reason' => null, 'code' => 'eligible'];
    }

    protected function no(string $code, string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'code' => $code];
    }

    // ── Investor ────────────────────────────────────────────────────────────────────────────────────────

    /** What the investor loan section shows. Visible only to ranks the programme covers, and only once it is open. */
    public function investorState(User $user): array
    {
        if (self::locked()) {
            // Nothing about lending is shown to investors while the programme is not authorised.
            return ['visible' => false, 'locked' => true, 'programme_open' => false, 'eligible' => false, 'reason' => null, 'disclaimer' => null, 'applications' => []];
        }

        $s = LoanProgrammeSetting::current();
        $rank = $user->investor_rank_id ? InvestorRank::find($user->investor_rank_id) : null;
        $rankCovered = $rank && in_array($rank->key, $s->eligible_rank_keys ?? [], true) && ($user->rank_status ?? 'active') === 'active';
        $e = $this->eligibility($user);

        return [
            'visible'       => (bool) ($rankCovered && $s->isOpen()),
            'locked'        => false,
            'programme_open' => $s->isOpen(),
            'eligible'      => $e['eligible'],
            'reason'        => $e['reason'],
            'disclaimer'    => BenefitDefaults::LOAN_DISCLAIMER,
            'applications'  => LoanApplication::with(['events', 'rank'])->where('user_id', $user->id)->orderByDesc('id')->get()
                                ->map(fn (LoanApplication $a) => $this->presentForInvestor($a))->all(),
        ];
    }

    public function apply(User $user, float $amount, ?string $purpose): LoanApplication
    {
        $this->assertUnlocked();
        return DB::transaction(function () use ($user, $amount, $purpose) {
            // Serialise per investor so two quick clicks cannot open two applications.
            User::whereKey($user->id)->lockForUpdate()->first();

            $e = $this->eligibility($user);
            if (!$e['eligible']) throw ValidationException::withMessages(['loan' => $e['reason']]);
            if ($amount <= 0) throw ValidationException::withMessages(['requested_amount' => 'Enter the amount you would like to apply for.']);

            $a = LoanApplication::create([
                'reference' => 'LN-' . strtoupper(Str::random(8)), 'user_id' => $user->id, 'rank_id_at_application' => $user->investor_rank_id,
                'requested_amount' => round($amount, 2), 'purpose' => $purpose, 'status' => 'submitted',
            ]);
            $this->event($a, 'submitted', $user, 'Application submitted. Submission does not guarantee approval.');

            return $a->load(['events', 'rank']);
        });
    }

    public function withdraw(User $user, LoanApplication $application): LoanApplication
    {
        $this->assertUnlocked();
        return DB::transaction(function () use ($user, $application) {
            $a = LoanApplication::lockForUpdate()->findOrFail($application->id);
            if ((int) $a->user_id !== (int) $user->id) abort(404);
            if (!in_array($a->status, LoanApplication::OPEN_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Only an application that is still open can be withdrawn.']);
            }
            $a->status = 'withdrawn';
            $a->save();
            $this->event($a, 'withdrawn', $user, 'Withdrawn by the investor.');

            return $a->load(['events', 'rank']);
        });
    }

    // ── Staff ───────────────────────────────────────────────────────────────────────────────────────────

    public function settings(): array
    {
        $s = LoanProgrammeSetting::current();

        return [
            'locked' => self::locked(), 'is_authorized' => $s->is_authorized, 'is_active' => $s->is_active, 'policy_reference' => $s->policy_reference,
            'eligible_rank_keys' => $s->eligible_rank_keys ?? [], 'notes' => $s->notes, 'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /** Authorise / deauthorise / activate / deactivate. Authorising needs a policy reference; activating needs authorisation. */
    public function configure(array $d, User $actor, string $reason): array
    {
        $this->assertUnlocked();
        return DB::transaction(function () use ($d, $actor, $reason) {
            $s = LoanProgrammeSetting::lockForUpdate()->first() ?? LoanProgrammeSetting::current();
            $before = $this->settings();

            if (array_key_exists('policy_reference', $d)) $s->policy_reference = $d['policy_reference'] ? trim($d['policy_reference']) : null;
            if (array_key_exists('notes', $d)) $s->notes = $d['notes'];
            if (array_key_exists('eligible_rank_keys', $d)) {
                $valid = InvestorRank::pluck('key')->all();
                $keys = array_values(array_unique($d['eligible_rank_keys']));
                if (array_diff($keys, $valid)) throw ValidationException::withMessages(['eligible_rank_keys' => 'Unknown rank.']);
                $s->eligible_rank_keys = $keys;
            }
            if (array_key_exists('is_authorized', $d)) {
                if ($d['is_authorized'] && mb_strlen((string) $s->policy_reference) < 5) {
                    throw ValidationException::withMessages(['policy_reference' => 'Record the reference of the approved lending policy before authorising the programme.']);
                }
                $s->is_authorized = (bool) $d['is_authorized'];
                if (!$s->is_authorized) $s->is_active = false;   // withdrawing authorisation closes the programme
            }
            if (array_key_exists('is_active', $d)) {
                if ($d['is_active'] && !$s->is_authorized) {
                    throw ValidationException::withMessages(['is_active' => 'The programme cannot be activated until it has been authorized.']);
                }
                $s->is_active = (bool) $d['is_active'];
            }
            $s->updated_by = $actor->id;
            $s->save();

            $this->benefits->audit('loan_programme', $s->id, 'configured', $actor, $before, $this->settings(), $reason);
            return $this->settings();
        });
    }

    public function list(?string $status = null)
    {
        return LoanApplication::with(['investor', 'rank', 'events'])
            ->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(25);
    }

    public function presentForStaff(LoanApplication $a): array
    {
        return [
            'id' => $a->id, 'reference' => $a->reference, 'investor_id' => $a->user_id, 'investor_name' => $a->investor?->name,
            'account_id' => $a->investor?->account_id, 'rank' => $a->rank?->badge(), 'requested_amount' => $a->requested_amount,
            'purpose' => $a->purpose, 'status' => $a->status, 'approved_terms' => $a->approved_terms, 'decision_reason' => $a->decision_reason,
            'decided_by_name' => $a->decided_by_name, 'decided_at' => $a->decided_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'events' => $a->events->map(fn ($e) => ['event' => $e->event, 'by' => $e->actor_name, 'role' => $e->actor_role, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String()])->all(),
        ];
    }

    public function presentForInvestor(LoanApplication $a): array
    {
        return [
            'id' => $a->id, 'reference' => $a->reference, 'rank' => $a->rank?->badge(), 'requested_amount' => $a->requested_amount,
            'purpose' => $a->purpose, 'status' => $a->status, 'approved_terms' => $a->status === 'approved' ? $a->approved_terms : null,
            'decision_reason' => $a->status === 'rejected' ? $a->decision_reason : null,
            'created_at' => $a->created_at?->toIso8601String(), 'decided_at' => $a->decided_at?->toIso8601String(),
            'timeline' => $a->events->map(fn ($e) => ['event' => $e->event, 'at' => $e->created_at?->toIso8601String()])->all(),
        ];
    }

    public function startReview(LoanApplication $application, User $actor, ?string $note): LoanApplication
    {
        $this->assertUnlocked();
        return $this->decideTransition($application, $actor, 'under_review', $note ?: 'Review started.', function (LoanApplication $a) {
            if ($a->status !== 'submitted') $this->fail('Only a submitted application can move to review.');
            $a->status = 'under_review';
        });
    }

    public function approve(LoanApplication $application, User $actor, string $terms, string $reason): LoanApplication
    {
        $this->assertUnlocked();
        return $this->decideTransition($application, $actor, 'approved', $reason, function (LoanApplication $a) use ($actor, $terms, $reason) {
            if ($a->status !== 'under_review') $this->fail('An application must be under review before it can be approved.');
            if (mb_strlen(trim($terms)) < 10) $this->fail('Write the approved terms (amount, fees, repayment arrangements) as agreed under the lending policy.', 'approved_terms');
            $a->status = 'approved'; $a->approved_terms = trim($terms);
            $a->decision_reason = $reason; $a->decided_by = $actor->id; $a->decided_by_name = $this->benefits->actorName($actor); $a->decided_at = now();
        });
    }

    public function reject(LoanApplication $application, User $actor, string $reason): LoanApplication
    {
        $this->assertUnlocked();
        return $this->decideTransition($application, $actor, 'rejected', $reason, function (LoanApplication $a) use ($actor, $reason) {
            if (!in_array($a->status, LoanApplication::OPEN_STATUSES, true)) $this->fail('Only an open application can be rejected.');
            $a->status = 'rejected';
            $a->decision_reason = $reason; $a->decided_by = $actor->id; $a->decided_by_name = $this->benefits->actorName($actor); $a->decided_at = now();
        });
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────

    protected function decideTransition(LoanApplication $application, User $actor, string $event, string $note, callable $mutate): LoanApplication
    {
        return DB::transaction(function () use ($application, $actor, $event, $note, $mutate) {
            $a = LoanApplication::lockForUpdate()->findOrFail($application->id);
            $mutate($a);
            $a->save();
            $this->event($a, $event, $actor, $note);

            // Tell the investor (database notification; no amounts or internal reasons).
            DB::afterCommit(fn () => rescue(function () use ($a, $event) {
                $a->loadMissing('investor');
                $a->investor?->notify(new \App\Notifications\InvestorRankNotification((string) Str::uuid(), [
                    'type' => 'loan_application', 'title' => 'Loan application update',
                    'message' => "Your loan application {$a->reference} is now: " . str_replace('_', ' ', $event) . '.',
                    'reference' => $a->reference, 'status' => $event,
                ]));
            }, null, true));

            return $a->load(['investor', 'rank', 'events']);
        });
    }

    protected function event(LoanApplication $a, string $event, User $actor, ?string $note): void
    {
        LoanApplicationEvent::create([
            'loan_application_id' => $a->id, 'event' => $event, 'actor_name' => $this->benefits->actorName($actor),
            'actor_role' => $actor->role ?? 'investor', 'note' => $note, 'created_at' => now(),
        ]);
    }

    protected function fail(string $message, string $field = 'status'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
