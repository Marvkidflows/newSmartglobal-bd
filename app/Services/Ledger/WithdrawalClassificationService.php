<?php
// LOCATION: app/Services/Ledger/WithdrawalClassificationService.php
//
// Splits a withdrawal into PRINCIPAL (for a named position) and verified PROFIT components (Financial Team §2D). Done while the
// withdrawal is still pending/on hold. It records the classification only: nothing reduces principal until the withdrawal is APPROVED
// and processed (PrincipalService::applyWithdrawal inside the approval transaction). A pending, rejected or cancelled withdrawal
// therefore never reduces eligible principal, and a profit component never does.

namespace App\Services\Ledger;

use App\Models\InvestmentAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalComponent;
use App\Services\Rank\RankBenefitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalClassificationService
{
    public function __construct(protected PrincipalService $principal, protected RankBenefitService $audit) {}

    /** @param array<int, array{component: string, amount: mixed, investment_id?: ?int, verification_note?: ?string}> $components */
    public function classify(Withdrawal $withdrawal, array $components, User $actor): array
    {
        if (!$this->principal->enabled()) abort(403, 'The principal ledger is not enabled in this environment.');

        return DB::transaction(function () use ($withdrawal, $components, $actor) {
            $w = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if (!in_array($w->status, ['pending', 'hold'], true)) $this->fail('Only a pending or on-hold withdrawal can be classified.');
            if (WithdrawalComponent::where('withdrawal_id', $w->id)->exists()) $this->fail('This withdrawal is already classified. Corrections are made through an approved principal correction, not by re-classifying.');
            if (!$components) $this->fail('Enter at least one component.');

            $sum = 0.0; $perPosition = []; $rows = [];
            foreach ($components as $c) {
                $type = $c['component'] ?? null;
                $amt  = round((float) ($c['amount'] ?? 0), 2);
                if (!in_array($type, ['principal', 'profit'], true) || $amt < 0.01) $this->fail('Each component needs a type (principal or profit) and a positive amount.');
                $invId = isset($c['investment_id']) ? (int) $c['investment_id'] : null;

                if ($type === 'principal') {
                    if (!$invId) $this->fail('A principal component must name the investment position it comes from. Account-level allocation is not approved.');
                    $pos = InvestmentAccount::whereKey($invId)->where('user_id', $w->user_id)->first();
                    if (!$pos || $pos->status === 'cancelled') $this->fail("Investment #{$invId} is not a redeemable position of this investor.");
                    $perPosition[$invId] = ($perPosition[$invId] ?? 0) + $amt;
                    if ($perPosition[$invId] > $this->principal->remaining($pos) + 0.0001) $this->fail("The principal for investment #{$invId} exceeds what remains in that position.");
                } else {
                    if (mb_strlen(trim((string) ($c['verification_note'] ?? ''))) < 10) $this->fail('A profit component needs the verification that it is profit available for withdrawal under the investment terms (at least 10 characters).');
                }
                $sum += $amt;
                $rows[] = ['investment_id' => $invId, 'component' => $type, 'amount' => $amt, 'note' => $c['verification_note'] ?? null];
            }
            if (abs($sum - (float) $w->amount) > 0.005) $this->fail('The components must add up to the withdrawal amount (' . number_format((float) $w->amount, 2) . ').');

            $created = [];
            foreach ($rows as $r) {
                $created[] = WithdrawalComponent::create([
                    'withdrawal_id' => $w->id, 'investment_id' => $r['investment_id'], 'component' => $r['component'], 'amount' => $r['amount'],
                    'component_key' => "w{$w->id}:i" . ($r['investment_id'] ?? 0) . ":{$r['component']}", 'verification_note' => $r['note'],
                    'classified_by' => $actor->id, 'classified_by_name' => $actor->name ?? $actor->full_name ?? 'Staff', 'created_at' => now(),
                ]);
            }
            $this->audit->audit('principal', $w->id, 'withdrawal_classified', $actor, null, ['withdrawal_id' => $w->id, 'components' => $rows], null);

            return $created;
        });
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['components' => [$message]]);
    }
}
