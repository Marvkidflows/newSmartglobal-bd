<?php
// LOCATION: app/Http/Controllers/Investor/InvestorReferralController.php
//
// TASK 5: investor-facing referral view. Original response keys are kept (referral_code, referral_link, total_referrals, active_referrals, total_bonus,
// referrals[]) and extended. READ-ONLY: no endpoint here accepts referral attribution or reward status - an investor cannot change who referred them,
// approve or credit a reward. Only the signed-in investor's OWN referrals are returned (queried by referrer_id = auth id); referred people are shown
// with a masked name/email only. Pending / approved / credited are always reported separately and a pending reward is never described as paid.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\ReferralPolicy;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Task5\ReferralService;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InvestorReferralController extends Controller
{
    // GET /investor-investment/investor/referrals
    public function index(Request $request)
    {
        $user = Auth::user();
        $link = app(ReferralService::class)->link($user);
        $policy = ReferralPolicy::current();

        $rewards = ReferralReward::where('referrer_id', $user->id)->with('referred:id,name,full_name,email,created_at')->latest('id')->get();

        $rows = $rewards->map(fn (ReferralReward $r) => [
            'id'                => $r->id,
            'reference'         => $r->reference,
            'name'              => $this->maskName($r->referred?->name ?? $r->referred?->full_name),
            'email'             => $this->maskEmail($r->referred?->email),
            'registration_status' => $r->registration_completed_at ? 'completed' : 'started',
            'registered_at'     => optional($r->registration_completed_at ?? $r->referred?->created_at)->toDateString(),
            'activation_status' => $r->activated_at ? 'activated' : 'not_activated',
            'eligibility'       => $r->eligibility,
            'status'            => $r->status,                                   // pending | approved | credited | rejected | reversed
            'bonus'             => $r->status === 'credited' ? (float) $r->amount : 0,   // legacy key: ONLY credited money counts
            'reward_amount'     => Decimal::money($r->amount),
            'currency'          => $r->currency,
            'paid'              => $r->status === 'credited',
            'credit_reference'  => $r->status === 'credited' || $r->status === 'reversed' ? $r->credit_reference : null,
            'explanation'       => $this->explain($r),
            'created_at'        => optional($r->created_at)->toDateString(),
        ]);

        // Referred people who have started but not completed registration (no reward row exists for them yet).
        $started = User::where('referred_by', $user->id)->where('role', 'investor')->whereNotIn('id', $rewards->pluck('referred_id'))->latest('id')->get(['id', 'name', 'full_name', 'email', 'created_at'])
            ->map(fn ($u) => [
                'id' => 'u' . $u->id, 'reference' => null, 'name' => $this->maskName($u->name ?? $u->full_name), 'email' => $this->maskEmail($u->email),
                'registration_status' => 'started', 'registered_at' => $u->created_at?->toDateString(), 'activation_status' => 'not_activated', 'eligibility' => 'not_checked',
                'status' => 'none', 'bonus' => 0, 'reward_amount' => null, 'currency' => 'USD', 'paid' => false, 'credit_reference' => null,
                'explanation' => 'Registration started but not completed yet - no reward can arise until it is completed.', 'created_at' => $u->created_at?->toDateString(),
            ]);
        $rows = $rows->concat($started);

        $sum = fn (string $st) => Decimal::fromCents((int) $rewards->where('status', $st)->sum(fn ($r) => Decimal::toCents(Decimal::money($r->amount))));
        $data = [
            'referral_code'    => $link['code'],
            'referral_link'    => $link['link'] ? url('/register?ref=' . $link['code']) : null,
            'total_referrals'  => $rows->count(),
            'active_referrals' => $rewards->whereNotNull('activated_at')->count(),
            'total_bonus'      => (float) $sum('credited'),                      // credited only
            'referrals'        => $rows->values(),
            'totals' => ['pending' => $sum('pending'), 'approved' => $sum('approved'), 'credited' => $sum('credited'), 'rejected' => $sum('rejected'), 'reversed' => $sum('reversed'), 'currency' => 'USD'],
            'counts' => ['pending' => $rewards->where('status', 'pending')->count(), 'approved' => $rewards->where('status', 'approved')->count(), 'credited' => $rewards->where('status', 'credited')->count(),
                'rejected' => $rewards->where('status', 'rejected')->count(), 'reversed' => $rewards->where('status', 'reversed')->count()],
            'programme' => [
                'active'          => (bool) $policy?->policy_approved,
                'reward_amount'   => ($policy && $policy->policy_approved) ? Decimal::money($policy->reward_amount) : null,   // integration: an unapproved amount is never advertised to investors
                'currency'        => $policy->currency ?? 'USD',
                'conditions'      => $this->conditions($policy),
            ],
        ];

        if ($request->expectsJson()) return response()->json($data);
        return view('investor.referrals.index', $data);
    }

    protected function conditions(?ReferralPolicy $p): array
    {
        if (!$p || !$p->policy_approved) {
            return ['The referral reward conditions have not been finalised yet. Referrals are being recorded, but no reward can be approved until SSI publishes the approved conditions.'];
        }
        $c = $p->criteria ?? []; $out = ['The person you refer must complete registration and have an active, email-verified account.'];
        if (!empty($c['require_kyc_approved'])) $out[] = 'Their identity (KYC) verification must be approved.';
        if (!empty($c['require_approved_deposit'])) $out[] = 'They must have at least one approved deposit.';
        if (!empty($c['require_investment'])) $out[] = 'They must have a qualifying investment.';
        if (!empty($c['require_completed_cycle'])) $out[] = 'They must have completed an investment cycle.';
        if (!empty($c['referrer_must_be_active'])) $out[] = 'Your own account must be active.';
        $out[] = 'Each reward is reviewed and approved by SSI Financial Administration before it is credited to your wallet. Self-referrals and duplicate accounts do not qualify.';
        return $out;
    }

    protected function explain(ReferralReward $r): string
    {
        return match ($r->status) {
            'credited' => 'Reward credited to your wallet.',
            'approved' => 'Approved - awaiting credit to your wallet. Not yet paid.',
            'rejected' => 'This referral did not qualify for a reward.' . ($r->decision_reason ? ' Reason: ' . $r->decision_reason : ''),
            'reversed' => 'This reward was reversed.' . ($r->reversal_reason ? ' Reason: ' . $r->reversal_reason : ''),
            default    => $this->pendingExplanation($r),
        };
    }

    protected function pendingExplanation(ReferralReward $r): string
    {
        $failed = collect($r->eligibility_detail['checks'] ?? [])->filter(fn ($c) => !($c['passed'] ?? true))->pluck('detail')->values();
        if ($r->eligibility === 'policy_not_approved') return 'Pending - the referral conditions have not been finalised yet. This is not a payment.';
        if ($failed->isNotEmpty()) return 'Pending - not yet eligible: ' . $failed->first();
        if ($r->suspicious) return 'Pending - under review by SSI Financial Administration.';
        return $r->eligibility === 'eligible' ? 'Eligible - awaiting Financial Administration approval. Not yet paid.' : 'Pending - eligibility has not been confirmed yet. This is not a payment.';
    }

    protected function maskName(?string $n): string
    {
        $n = trim((string) $n); if ($n === '') return 'Investor';
        $parts = preg_split('/\s+/', $n);
        return $parts[0] . (count($parts) > 1 ? ' ' . Str::upper(Str::substr(end($parts), 0, 1)) . '.' : '');
    }

    protected function maskEmail(?string $e): string
    {
        if (!$e || !str_contains($e, '@')) return '';
        [$local, $domain] = explode('@', $e, 2);
        return Str::substr($local, 0, 1) . '***@' . $domain;
    }
}
