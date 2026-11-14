<?php
// LOCATION: app/Services/Registration/RegistrationReviewService.php
//
// Task 2 — every reviewer action on an application goes through here, inside one DB transaction with the application row locked, so a status
// change, its audit event and (for a final decision) the user's KYC flag commit or roll back together.
//
// AUTHORISATION is enforced twice on purpose: the routes use the 'registration.team' middleware and the controllers call Gate::authorize(),
// and this service re-checks the actor's role for approvals/rejections so no future caller can bypass it.
//
// FINANCIAL ISOLATION: this service reads/writes ONLY registration_* tables and the users.kyc_* fields (via the same forceFill the existing
// AdminKycController uses). It never reads or writes balances, deposits, withdrawals, investments or any financial table.
//
// RANK INTERFACE (no change to the Rank module): approving/rejecting writes users.kyc_status / kyc_verified, which the EXISTING
// User::updated hook in AppServiceProvider already observes to queue a rank re-evaluation. This service does not call any Rank code.

namespace App\Services\Registration;

use App\Models\RegistrationApplication as Row;
use App\Models\RegistrationCommunication;
use App\Models\RegistrationEvent;
use App\Models\User;
use App\Notifications\RegistrationApplicantNotification;
use Illuminate\Support\Facades\DB;

class RegistrationReviewService
{
    public function __construct(private RegistrationCommunicationService $comms) {}

    public static function roleMayReview(?User $u): bool
    {
        return $u && in_array($u->role, ['registration_member', 'registration_lead', 'admin'], true);
    }

    public static function roleMayDecide(?User $u): bool
    {
        return $u && in_array($u->role, ['registration_lead', 'admin'], true);
    }

    /** Which target statuses this actor may choose for this application right now (drives the UI buttons; the server re-validates). */
    public function allowedTransitions(Row $app, User $actor): array
    {
        if (!self::roleMayReview($actor)) return [];
        $targets = Row::TRANSITIONS[$app->review_status] ?? [];
        return array_values(array_filter($targets, fn ($t) => !in_array($t, Row::DECISION_STATUSES, true) || self::roleMayDecide($actor)));
    }

    /**
     * Move an application to $to.
     * @throws RegistrationRuleException when a rule refuses the change
     */
    public function transition(Row $app, User $actor, string $to, ?string $note = null): Row
    {
        if (!self::roleMayReview($actor)) {
            throw new RegistrationRuleException('You do not have permission to review applications.');
        }
        if (!in_array($to, Row::STATUSES, true)) {
            throw new RegistrationRuleException('Unknown status.');
        }
        if (in_array($to, Row::DECISION_STATUSES, true) && !self::roleMayDecide($actor)) {
            throw new RegistrationRuleException('Only a Registration Team lead can approve or reject an application.');
        }

        $note = $note !== null ? trim($note) : null;
        $notify = null;   // [title, message, kind, subject] applicant notice to send AFTER the transaction commits

        $result = DB::transaction(function () use ($app, $actor, $to, $note, &$notify) {
            $locked = Row::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $from = $locked->review_status;

            if (!in_array($to, Row::TRANSITIONS[$from] ?? [], true)) {
                throw new RegistrationRuleException('An application cannot move from "' . (Row::LABELS[$from] ?? $from) . '" to "' . (Row::LABELS[$to] ?? $to) . '".');
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $updates = ['last_activity_at' => now()];

            switch ($to) {
                case Row::UNDER_REVIEW:
                    $updates['review_started_at'] = $locked->review_started_at ?? now();
                    if (!$locked->assigned_to) $updates['assigned_to'] = $actor->id;
                    if ($from === Row::REJECTED) {   // reopening a rejected application clears the decision (history is kept in events)
                        $updates += ['decided_at' => null, 'decided_by' => null, 'decision_reason' => null];
                        $this->setUserKyc($user, 'pending', false, null);
                    }
                    break;

                case Row::SUBMITTED:   // released back to the queue
                    $updates['assigned_to'] = null;
                    break;

                case Row::ADDITIONAL_INFO_REQUIRED:
                    if ($note === null || $note === '') {
                        throw new RegistrationRuleException('Say what additional information the applicant must provide.');
                    }
                    $updates['info_request'] = $note;
                    $notify = ['Additional information required', $note, 'info_request', 'Additional information needed for your SSI application'];
                    break;

                case Row::APPROVED:
                    if (!$user->registration_completed) {
                        throw new RegistrationRuleException('Registration is not complete, so it cannot be approved.');
                    }
                    if (!$user->id_document_path) {
                        throw new RegistrationRuleException('No KYC documents have been submitted, so this application cannot be approved.');
                    }
                    $updates += ['decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => null, 'info_request' => null];
                    $this->setUserKyc($user, 'approved', true, null);
                    $notify = ['Application approved', 'Your identity verification has been approved.', 'status_notice', 'Your SSI application has been approved'];
                    break;

                case Row::REJECTED:
                    if ($note === null || $note === '') {
                        throw new RegistrationRuleException('A reason is required to reject an application.');
                    }
                    $updates += ['decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => $note];
                    $this->setUserKyc($user, 'rejected', false, $note);
                    $notify = ['Application not approved', $note, 'status_notice', 'Update on your SSI application'];
                    break;
            }

            $locked->forceFill($updates + ['review_status' => $to])->save();

            $event = RegistrationEvent::record('status_changed', $locked, $actor, $from, $to, $note);
            return [$locked, $event];
        });

        [$fresh, $event] = $result;

        if ($notify) {
            [$title, $message, $kind, $subject] = $notify;
            $user = User::find($fresh->user_id);
            // In-app notice for the applicant, plus a logged + deduplicated email. Failures are recorded, never thrown, so a mail outage
            // cannot undo or block the review decision that has already committed.
            try { $user?->notify(new RegistrationApplicantNotification($title, $message, 'kyc_status')); } catch (\Throwable $e) { \Log::warning('Applicant notice failed: ' . $e->getMessage()); }
            $this->comms->sendTransactional($fresh, $actor, $kind === 'info_request' ? RegistrationCommunication::KIND_INFO_REQUEST : RegistrationCommunication::KIND_STATUS, $subject, $message, "status:{$fresh->id}:{$event->id}");
        }

        return $fresh->fresh();
    }

    /** Internal review note. Visible only to the Registration Team and Admin; never sent to the applicant. */
    public function addNote(Row $app, User $actor, string $note): RegistrationEvent
    {
        if (!self::roleMayReview($actor)) throw new RegistrationRuleException('You do not have permission to review applications.');
        $note = trim($note);
        if ($note === '') throw new RegistrationRuleException('A note cannot be empty.');

        $app->forceFill(['last_activity_at' => now()])->save();
        return RegistrationEvent::record('note_added', $app, $actor, null, null, $note);
    }

    /** Claim an application ($assignee = actor) or, for a lead/admin, assign it to another team member or clear it ($assignee = null). */
    public function assign(Row $app, User $actor, ?User $assignee): Row
    {
        if (!self::roleMayReview($actor)) throw new RegistrationRuleException('You do not have permission to review applications.');

        if ($assignee && !in_array($assignee->role, ['registration_member', 'registration_lead'], true)) {
            throw new RegistrationRuleException('Applications can only be assigned to Registration Team members.');
        }
        if ($assignee && $assignee->status !== 'active') {
            throw new RegistrationRuleException('That team member\'s account is not active.');
        }
        $selfClaim = $assignee && $assignee->id === $actor->id;
        if (!$selfClaim && !self::roleMayDecide($actor)) {
            throw new RegistrationRuleException('Only a lead can assign applications to other people.');
        }

        return DB::transaction(function () use ($app, $actor, $assignee) {
            $locked = Row::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->assigned_to;
            $locked->forceFill(['assigned_to' => $assignee?->id, 'last_activity_at' => now()])->save();
            RegistrationEvent::record('assigned', $locked, $actor, null, null, null, ['from' => $previous, 'to' => $assignee?->id]);
            return $locked;
        });
    }

    /** Record (or lift) an applicant's request not to receive follow-up messages. */
    public function setFollowUpOptOut(Row $app, User $actor, bool $optedOut, ?string $note): Row
    {
        if (!self::roleMayReview($actor)) throw new RegistrationRuleException('You do not have permission to review applications.');

        return DB::transaction(function () use ($app, $actor, $optedOut, $note) {
            $locked = Row::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['followup_opt_out_at' => $optedOut ? ($locked->followup_opt_out_at ?? now()) : null])->save();
            RegistrationEvent::record($optedOut ? 'followup_opt_out' : 'followup_opt_in', $locked, $actor, null, null, $note);
            return $locked;
        });
    }

    /** Same field set and mechanism as AdminKycController::approve/reject — KYC state is not mass-assignable. */
    private function setUserKyc(User $user, string $status, bool $verified, ?string $reason): void
    {
        if ($user->role !== 'investor') {
            throw new RegistrationRuleException('Only investor applications can be verified.');
        }
        $user->forceFill([
            'kyc_status'           => $status,
            'kyc_verified'         => $verified,
            'kyc_verified_at'      => $verified ? now() : null,
            'kyc_rejection_reason' => $reason,
        ])->save();
    }
}
