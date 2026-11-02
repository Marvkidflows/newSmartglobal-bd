<?php
// LOCATION: app/Services/Rank/PrincipalPolicy.php
//
// The activation gate for money rank criteria. A criterion that measures current eligible principal cannot be ACTIVATED until:
//   (1) the Financial controls are switched on in this environment (separate deposit approval + the ledger), so the figure the rank
//       uses is not distorted by funds that are both wallet cash and active principal; and
//   (2) the formal sign-offs are on record: the Financial Team's decisions adopted, and the release authorised.
// Drafts, cycles-only rules and anything already active are unaffected. This replaces the earlier "investment basis" gate: the Board
// has decided the basis (current verified eligible principal), so the only question left is whether the controls and approvals exist.

namespace App\Services\Rank;

use App\Models\RankPolicyDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrincipalPolicy
{
    public const TOPICS = [
        'financial_decisions_adopted' => 'Financial Team decisions of 27 October 2026 formally adopted by SSI management',
        'release_authorization'       => 'Production release authorised (after staging validation)',
    ];
    public const FIELDS = [CoreRequirements::AMOUNT_FIELD];
    public const ADMIN_PATH = '/admin/ranks/benefits';

    public function __construct(protected RankBenefitService $audit) {}

    public function required(): bool
    {
        return (bool) config('ranks.require_principal_policy', true);
    }

    /** Newest row per topic, if it is a confirmation. */
    public function confirmed(string $topic): ?RankPolicyDecision
    {
        $row = RankPolicyDecision::where('topic', $topic)->orderByDesc('id')->first();
        return ($row && $row->status === 'confirmed') ? $row : null;
    }

    public function controlsEnabled(): bool
    {
        return (bool) config('financial.separate_deposit_approval') && (bool) config('financial.ledger_enabled');
    }

    public function allowsActivation(): bool
    {
        if (!$this->required()) return true;
        if (!$this->controlsEnabled()) return false;
        foreach (array_keys(self::TOPICS) as $t) if (!$this->confirmed($t)) return false;
        return true;
    }

    /** @return array<int, array<string, mixed>> blockers in the shape the criteria validator uses */
    public function blockers(): array
    {
        if ($this->allowsActivation()) return [];
        $why = [];
        if (!$this->controlsEnabled()) {
            $why[] = 'The Financial controls are not enabled in this environment (separate deposit approval and the wallet/principal ledger). Until they are, funds can be both wallet cash and active principal, so the figure would be unreliable.';
        }
        foreach (self::TOPICS as $t => $label) {
            if (!$this->confirmed($t)) $why[] = "Not yet recorded: {$label}.";
        }

        return [['type' => 'principal_policy', 'message' => 'Cannot activate a rule that measures current eligible principal yet. ' . implode(' ', $why),
                 'action' => ['label' => 'Open the policy panel', 'path' => self::ADMIN_PATH]]];
    }

    public function state(): array
    {
        $topics = [];
        foreach (self::TOPICS as $t => $label) {
            $c = $this->confirmed($t);
            $topics[] = ['topic' => $t, 'label' => $label, 'confirmed' => (bool) $c,
                         'approval_reference' => $c?->approval_reference, 'decided_by_name' => $c?->decided_by_name, 'at' => $c?->created_at?->toIso8601String()];
        }

        return [
            'required' => $this->required(), 'controls_enabled' => $this->controlsEnabled(), 'allows_activation' => $this->allowsActivation(),
            'flags' => ['separate_deposit_approval' => (bool) config('financial.separate_deposit_approval'), 'ledger_enabled' => (bool) config('financial.ledger_enabled'),
                        'crypto_rank_valuation_enabled' => (bool) config('financial.crypto_rank_valuation_enabled'), 'live_fx_enabled' => (bool) config('financial.live_fx_enabled')],
            'topics' => $topics,
            'measure' => 'Current eligible principal (verified): verified principal still invested in qualifying active positions. Completed or redeemed principal no longer counts; completed cycles still do. Wallet cash never counts.',
            'pending_formal_approval' => [
                'The account-level redemption fallback rule (not implemented)', 'The approved exchange-rate providers and fallback sources', 'Maximum acceptable rate age by transaction type',
                'The final rounding convention where accounting or provider requirements differ', 'Any cryptocurrency valuation and rank-eligibility policy', 'The authorised approvers and production-release authority',
            ],
        ];
    }

    public function record(string $topic, string $status, string $reference, ?string $note, User $actor): RankPolicyDecision
    {
        if (!array_key_exists($topic, self::TOPICS)) throw ValidationException::withMessages(['topic' => 'Unknown policy topic.']);
        if (mb_strlen(trim($reference)) < 5) throw ValidationException::withMessages(['approval_reference' => 'Enter the reference of the written approval (at least 5 characters).']);

        return DB::transaction(function () use ($topic, $status, $reference, $note, $actor) {
            $before = $this->state();
            $d = RankPolicyDecision::create(['topic' => $topic, 'status' => $status, 'approval_reference' => trim($reference), 'note' => $note,
                                             'decided_by' => $actor->id, 'decided_by_name' => $this->audit->actorName($actor), 'created_at' => now()]);
            $this->audit->audit('policy_decision', $d->id, $status === 'confirmed' ? 'confirmed' : 'revoked', $actor, $before, $this->state(), $note ?: "{$topic}: {$status}");
            return $d;
        });
    }
}
