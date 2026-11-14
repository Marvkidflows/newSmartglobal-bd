<?php
// LOCATION: app/Services/Financial/FinancialApprovalService.php
//
// Second-approval ("four eyes") workflow for high-risk financial operations (F-03 / F-04 / F-14 / R7 / R8).
//
// STATES   pending → executed | rejected | cancelled | expired | failed (→ retry → executed | failed …)
//          'executing' exists only INSIDE the claim transaction and is never committed; 'approved' is a LEGACY value written by the earlier
//          two-commit workflow and is handled by recoverStuck().
// WHY ONE TRANSACTION   The earlier design committed "approved" and executed afterwards in a second commit, so a crash in between left a
//          request approved-but-unexecuted forever. Now the claim, the execution and the final status are ONE outer transaction: either all of
//          it commits (executed + the money/record change + audit rows + events) or none of it does (the request is exactly as it was and can be
//          approved again). A failed EXECUTION is rolled back by a savepoint and the 'failed' record (error + event) is then committed.
// HARD RULES (not configurable)   the approver is never the requester; approver AND requester must be ACTIVE staff with an allowed role at
//          execution time (re-read from the database, not from the request); a request is decided once; the payload hash is verified before
//          execution; an Idempotency-Key bound to one payload cannot return a request for a different payload (409); at most one pending request
//          exists per identical payload (DB-unique open_key).
// CONFIGURABLE (config/financial_controls.php; values are placeholders pending Management)   which operations need approval, thresholds,
//          cumulative window, approver roles, expiry.
namespace App\Services\Financial;

use App\Models\FinancialApprovalEvent;
use App\Models\FinancialApprovalRequest as Req;
use App\Models\User;
use App\Support\StaffAccountGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialApprovalService
{
    public const OPERATIONS = ['balance_adjust', 'bulk_balance', 'profit_adjust', 'profit_reverse', 'investment_correction'];
    public const INITIATOR_ROLES = ['admin', 'financial'];

    public function __construct(
        protected BalanceAdjustmentService $balances,
        protected ProfitAdjustmentService $profits,
        protected InvestmentCorrectionService $corrections,
    ) {}

    // ── hashing ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    /** Canonical form: associative arrays are key-sorted RECURSIVELY (MySQL's JSON type re-orders object keys, so a stored payload must hash like the original). Lists keep their order. */
    protected static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        $isList = array_is_list($v);
        $out = array_map(fn ($x) => self::canonical($x), $v);
        if (!$isList) ksort($out);
        return $out;
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    // ── submit ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function submit(string $operation, array $payload, User $requester, ?string $idempotencyKey = null): Req
    {
        return $this->submitDetailed($operation, $payload, $requester, $idempotencyKey)['request'];
    }

    /** @return array{request: Req, duplicate: bool} */
    public function submitDetailed(string $operation, array $payload, User $requester, ?string $idempotencyKey = null): array
    {
        if (!in_array($operation, self::OPERATIONS, true)) throw new \InvalidArgumentException('Unknown operation.');
        if (!StaffAccountGuard::isActiveStaffWithRole($requester, self::INITIATOR_ROLES)) {
            throw ValidationException::withMessages(['approval' => ['Your account is not authorised to request this operation.']]);
        }
        $hash = self::hash($payload);
        $key = $idempotencyKey ? "{$requester->id}:{$operation}:{$idempotencyKey}" : null;

        if ($key && ($existing = Req::where('idempotency_key', $key)->first())) {
            return ['request' => $this->sameOrConflict($existing, $hash), 'duplicate' => true];       // same key + same payload → the original; different payload → 409
        }
        $openKey = "{$operation}:{$hash}";
        if ($open = Req::where('open_key', $openKey)->first()) return ['request' => $open, 'duplicate' => true];   // an identical request is already pending

        [$target, $amountUsd, $authRef] = $this->describe($operation, $payload);
        $hours = config('financial_controls.approval_expiry_hours');
        try {
            $r = DB::transaction(function () use ($operation, $payload, $hash, $key, $openKey, $requester, $target, $amountUsd, $authRef, $hours) {
                $r = Req::create([
                    'operation' => $operation, 'payload' => $payload, 'payload_hash' => $hash, 'status' => Req::PENDING, 'idempotency_key' => $key,
                    'requested_by' => $requester->id, 'target_user_id' => $target, 'amount_usd' => $amountUsd, 'authorization_reference' => $authRef,
                    'open_key' => $openKey, 'expires_at' => ($hours !== null && trim((string) $hours) !== '') ? now()->addHours((int) $hours) : null,
                ]);
                $this->event($r, 'submitted', $requester, null, Req::PENDING, null, ['operation' => $operation]);
                return $r;
            });
        } catch (UniqueConstraintViolationException $e) {                                              // lost a race with an identical submission → return the winner
            $winner = ($key ? Req::where('idempotency_key', $key)->first() : null) ?? Req::where('open_key', $openKey)->first();
            if (!$winner) throw $e;
            return ['request' => $this->sameOrConflict($winner, $hash), 'duplicate' => true];
        }
        return ['request' => $r, 'duplicate' => false];
    }

    protected function sameOrConflict(Req $existing, string $hash): Req
    {
        if (!hash_equals($existing->payload_hash, $hash)) {
            throw new ConflictHttpException('This Idempotency-Key was already used for a different request. Use a new key for a new request.');
        }
        return $existing;
    }

    /** @return array{0:?int,1:?string,2:?string} target user id, monetary size (for cumulative checks), authorisation reference */
    protected function describe(string $operation, array $p): array
    {
        return match ($operation) {
            'balance_adjust'        => [isset($p['user_id']) ? (int) $p['user_id'] : null, in_array($p['type'] ?? '', ['add', 'deduct'], true) ? (string) $p['amount'] : null, null],
            'investment_correction' => [isset($p['user_id']) ? (int) $p['user_id'] : null, null, $p['authorization_reference'] ?? null],
            'profit_adjust'         => [null, null, $p['authorization_reference'] ?? null],
            default                 => [null, null, null],
        };
    }

    // ── permissions ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    /** Everything is re-read from the database at decision/execution time; nothing is trusted from the session or the stored request. */
    protected function assertMayDecide(Req $r, User $approver): User
    {
        $fresh = User::find($approver->id);
        if (!$fresh) throw ValidationException::withMessages(['approval' => ['Your account is not authorised to decide this request.']]);
        if ((int) $fresh->id === (int) $r->requested_by) {
            throw ValidationException::withMessages(['approval' => ['You cannot decide your own request. A different authorised person must approve it.']]);
        }
        $roles = (array) config("financial_controls.approver_roles.{$r->operation}", []);
        if (!StaffAccountGuard::isActiveStaffWithRole($fresh, $roles)) {
            throw ValidationException::withMessages(['approval' => ['Your account is not authorised to decide this request.']]);
        }
        return $fresh;
    }

    protected function assertRequesterStillAuthorised(Req $r): User
    {
        $req = User::find($r->requested_by);
        if (!StaffAccountGuard::isActiveStaffWithRole($req, self::INITIATOR_ROLES)) {
            throw ValidationException::withMessages(['approval' => ['The requester\'s account is no longer active staff, so this request cannot be executed. Reject or cancel it.']]);
        }
        return $req;
    }

    protected function assertIntegrity(Req $r): void
    {
        if (!hash_equals((string) $r->payload_hash, self::hash((array) $r->payload))) {
            throw ValidationException::withMessages(['approval' => ['Request payload integrity check failed.']]);
        }
    }

    // ── approve / retry (claim + execute in ONE transaction) ─────────────────────────────────────────────────────────────────────────
    /** @return array{request: Req, result: array} */
    public function approve(int $id, User $approver, ?string $note = null): array
    {
        return $this->claimAndExecute($id, $approver, $note, false);
    }

    /** A 'failed' request may be retried by an authorised, different person once whatever blocked it has been resolved. Every check is repeated. */
    public function retry(int $id, User $approver, ?string $note = null): array
    {
        return $this->claimAndExecute($id, $approver, $note, true);
    }

    protected function claimAndExecute(int $id, User $approver, ?string $note, bool $isRetry): array
    {
        $outcome = DB::transaction(function () use ($id, $approver, $note, $isRetry) {
            $r = Req::whereKey($id)->lockForUpdate()->first();                                       // 1. lock: two approvers cannot both proceed
            if (!$r) return ['error' => 'Approval request not found.'];

            if ($r->status === Req::PENDING && $r->expires_at && $r->expires_at->isPast()) {
                $this->transition($r, Req::EXPIRED, 'expired', null, 'Expired before a decision was made.');
                return ['error' => 'This request has expired and can no longer be approved. Submit a new request.'];
            }
            if ($r->status !== ($isRetry ? Req::FAILED : Req::PENDING)) {
                return ['error' => $isRetry ? 'Only a failed request can be retried.' : 'This request has already been decided.'];
            }
            if ($isRetry && ($r->result['needs_manual_verification'] ?? false)) {
                // Recovery could not prove whether this ever executed. Retrying could apply it TWICE, so only a human who has checked the
                // records may act: cancel it, then submit a fresh request if the change is still needed.
                return ['error' => 'This request was interrupted and its execution state could not be determined. Verify the affected records manually, then cancel it and submit a new request if still needed. It cannot be retried.'];
            }
            try {                                                                                   // 2. re-validate everyone and the payload
                $fresh = $this->assertMayDecide($r, $approver);
                $requester = $this->assertRequesterStillAuthorised($r);
                $this->assertIntegrity($r);
                if ($isRetry && Req::where('open_key', "{$r->operation}:{$r->payload_hash}")->where('id', '!=', $r->id)->exists()) {
                    throw ValidationException::withMessages(['approval' => ['An identical request is already pending. Decide that one instead.']]);
                }
            } catch (ValidationException $e) {
                return ['error' => collect($e->errors())->flatten()->first()];
            }

            $from = $r->status;
            $r->forceFill(['status' => Req::EXECUTING, 'decided_by' => $fresh->id, 'decided_at' => now(), 'decision_note' => $note,
                'execution_started_at' => now(), 'execution_attempts' => (int) $r->execution_attempts + 1, 'last_error' => null])->save();
            $this->event($r, $isRetry ? 'retried' : 'approved', $fresh, $from, Req::EXECUTING, $note);
            $this->event($r, 'execution_started', $fresh, Req::EXECUTING, Req::EXECUTING, null, ['attempt' => $r->execution_attempts]);

            try {                                                                                   // 3. execute in a SAVEPOINT: failure rolls back only the money/record change
                $result = DB::transaction(fn () => $this->execute($r, $requester, $fresh));
            } catch (\Throwable $e) {
                // A deadlock / lock-wait timeout makes MySQL roll back the WHOLE outer transaction, so we must not try to write a 'failed' row into it:
                // rethrow and let DB::transaction(…, 3) re-run the claim from scratch (nothing was committed, every check is repeated).
                if ($this->isConcurrencyError($e)) throw $e;
                $msg = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->implode(' ') : 'Execution failed unexpectedly; nothing was changed. See the server log.';
                if (!($e instanceof ValidationException)) report($e);
                $r->forceFill(['status' => Req::FAILED, 'last_error' => $msg, 'result' => ['error' => $msg], 'open_key' => null])->save();
                $this->event($r, 'failed', $fresh, Req::EXECUTING, Req::FAILED, $msg);
                return ['error' => $msg];                                                            // the 'failed' record COMMITS; the money change did not happen
            }

            $r->forceFill(['status' => Req::EXECUTED, 'executed_at' => now(), 'result' => $result, 'last_error' => null, 'open_key' => null])->save();
            $this->event($r, 'executed', $fresh, Req::EXECUTING, Req::EXECUTED, null, ['result' => $result]);
            return ['request' => $r->fresh(), 'result' => $result];
        }, 3);

        if (isset($outcome['error'])) throw ValidationException::withMessages(['approval' => [$outcome['error']]]);

        // Notifications only AFTER a successful commit, and never allowed to undo or fail an executed request.
        try { $this->corrections->notifyAfterExecution($outcome['request'], $outcome['result']); } catch (\Throwable $e) { report($e); }
        return $outcome;
    }

    // ── reject / cancel / expire / recover ───────────────────────────────────────────────────────────────────────────────────────────
    public function reject(int $id, User $approver, string $note): Req
    {
        return DB::transaction(function () use ($id, $approver, $note) {
            $r = Req::whereKey($id)->lockForUpdate()->first();
            if (!$r) throw ValidationException::withMessages(['approval' => ['Approval request not found.']]);
            if ($r->status !== Req::PENDING) throw ValidationException::withMessages(['approval' => ['This request has already been decided.']]);
            $fresh = $this->assertMayDecide($r, $approver);                                            // self-rejection is refused here too
            $r->forceFill(['decided_by' => $fresh->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->transition($r, Req::REJECTED, 'rejected', $fresh, $note);
            return $r;
        });
    }

    /** The requester — or an active admin — may withdraw a pending or failed request. Nothing has moved, so nothing needs reversing. */
    public function cancel(int $id, User $actor, string $reason): Req
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $r = Req::whereKey($id)->lockForUpdate()->first();
            if (!$r) throw ValidationException::withMessages(['approval' => ['Approval request not found.']]);
            $fresh = User::find($actor->id);
            $allowed = $fresh && StaffAccountGuard::isActive($fresh) && ((int) $fresh->id === (int) $r->requested_by || $fresh->role === 'admin');
            if (!$allowed) throw ValidationException::withMessages(['approval' => ['Only the requester or an administrator can cancel this request.']]);
            if (!in_array($r->status, Req::OPEN, true)) throw ValidationException::withMessages(['approval' => ['Only a pending or failed request can be cancelled.']]);
            $r->forceFill(['cancelled_by' => $fresh->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->transition($r, Req::CANCELLED, 'cancelled', $fresh, $reason);
            return $r;
        });
    }

    /** Marks overdue PENDING requests expired. A no-op unless financial_controls.approval_expiry_hours is configured (Management decision #8). */
    public function expireDue(): int
    {
        $n = 0;
        foreach (Req::where('status', Req::PENDING)->whereNotNull('expires_at')->where('expires_at', '<', now())->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$n) {
                $r = Req::whereKey($id)->lockForUpdate()->first();
                if ($r && $r->status === Req::PENDING && $r->expires_at && $r->expires_at->isPast()) {
                    $this->transition($r, Req::EXPIRED, 'expired', null, 'Expired before a decision was made.');
                    $n++;
                }
            });
        }
        return $n;
    }

    /**
     * Recovery for requests left 'executing' or legacy 'approved' by the earlier two-commit workflow (or an interrupted run). It NEVER guesses
     * about money: it checks for the execution's own records first. Found → marked executed. Provably absent → marked failed (retryable).
     * Cannot be determined → marked failed with an explicit "manual verification required" note.
     * @return array<int,array{id:int,outcome:string}>
     */
    public function recoverStuck(int $olderThanMinutes = 15): array
    {
        $report = [];
        $ids = Req::whereIn('status', [Req::EXECUTING, Req::LEGACY_APPROVED])->where('updated_at', '<', now()->subMinutes($olderThanMinutes))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$report) {
                $r = Req::whereKey($id)->lockForUpdate()->first();
                if (!$r || !in_array($r->status, [Req::EXECUTING, Req::LEGACY_APPROVED], true)) return;
                $state = $this->inspectExecution($r);
                $from = $r->status;
                if ($state === 'executed') {
                    $r->forceFill(['executed_at' => $r->executed_at ?? now(), 'last_error' => null, 'open_key' => null])->save();
                    $this->transition($r, Req::EXECUTED, 'recovered', null, 'Execution records were found; status reconciled to executed.');
                } else {
                    $why = $state === 'not_executed'
                        ? 'Interrupted before execution; no execution records exist. It can be retried.'
                        : 'Interrupted; execution state could not be determined automatically. MANUAL VERIFICATION REQUIRED before any retry.';
                    $r->forceFill(['last_error' => $why, 'open_key' => null,
                        'result' => $state === 'unknown' ? ['needs_manual_verification' => true, 'error' => $why] : ['error' => $why]])->save();
                    $this->transition($r, Req::FAILED, 'recovered', null, $why);
                }
                $report[] = ['id' => (int) $id, 'outcome' => $state, 'from' => $from];
            });
        }
        return $report;
    }

    protected function inspectExecution(Req $r): string
    {
        $p = (array) $r->payload;
        return match ($r->operation) {
            'balance_adjust' => \App\Models\BalanceAdjustment::where('approval_request_id', $r->id)->exists() ? 'executed' : 'not_executed',
            'bulk_balance'   => \App\Models\BalanceAdjustment::where('approval_request_id', $r->id)->exists() ? 'executed' : 'unknown',
            'profit_adjust'  => \App\Models\ProfitAdjustment::where('approval_request_id', $r->id)->exists() ? 'executed' : 'not_executed',
            default          => 'unknown',
        };
    }

    // ── execution ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    protected function execute(Req $r, User $requester, User $approver): array
    {
        $p = (array) $r->payload;
        switch ($r->operation) {
            case 'balance_adjust':
                $o = $this->balances->adjust((int) $p['user_id'], $p['type'], $p['amount'] ?? null, $p['reason'], $requester, "approval-{$r->id}", $r->id, $approver->id, (bool) ($p['investor_only'] ?? false));
                return ['adjustment_id' => $o['adjustment']->id, 'balance_before' => (string) $o['adjustment']->balance_before, 'balance_after' => (string) $o['adjustment']->balance_after];
            case 'bulk_balance':
                return $this->balances->applyBulk($p, $requester, $r->id, $approver->id);
            case 'profit_adjust':
                $adj = $this->profits->apply($p, $requester, $approver, $r->id);
                return ['profit_adjustment_id' => $adj->id, 'affected' => $adj->affected_count];
            case 'profit_reverse':
                return $this->profits->reverse((int) $p['profit_adjustment_id'], $p['reason'], $requester, $approver, $r->id);
            case 'investment_correction':
                return $this->corrections->execute($p, $requester, $approver, $r->id);
        }
        throw new \LogicException('Unhandled operation.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    protected function transition(Req $r, string $to, string $event, ?User $actor, ?string $note): void
    {
        $from = $r->status;
        $r->forceFill(['status' => $to, 'open_key' => null])->save();
        $this->event($r, $event, $actor, $from, $to, $note);
    }

    protected function event(Req $r, string $event, ?User $actor, ?string $from, ?string $to, ?string $note = null, ?array $meta = null): void
    {
        FinancialApprovalEvent::create(['approval_request_id' => $r->id, 'event' => $event, 'actor_id' => $actor?->id, 'actor_role' => $actor?->role,
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'meta' => $meta, 'created_at' => now()]);
    }

    protected function isConcurrencyError(\Throwable $e): bool
    {
        if (!($e instanceof \Illuminate\Database\QueryException)) return false;
        $m = strtolower($e->getMessage());
        return in_array((string) $e->getCode(), ['40001', '1213', '1205'], true) || str_contains($m, 'deadlock') || str_contains($m, 'lock wait timeout');
    }
}
