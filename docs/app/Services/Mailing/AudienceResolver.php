<?php
// TASK 3 — resolves a campaign's audience. This is where public / investor separation is enforced for bulk sends:
//   * public audiences NEVER include verified, eligible investors;
//   * investor audiences ONLY include verified, eligible investors;
//   * consent-based kinds only include people with a current 'granted' subscription for the exact purpose.
// Rows are yielded lazily; nothing here returns an e-mail address to an HTTP client.
namespace App\Services\Mailing;

use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AudienceResolver
{
    public const AUDIENCES = [
        'public' => [
            'public_subscribers'       => 'Consented public subscribers (website sign-ups and contacts)',
            'registered_non_investors' => 'Registered users who are not yet verified investors (consented)',
        ],
        'investor' => [
            'verified_investors'         => 'All verified, eligible investors',
            'verified_investors_by_rank' => 'Verified, eligible investors in selected ranks',
        ],
    ];

    public static function valid(string $environment, string $audience): bool
    {
        return isset(self::AUDIENCES[$environment][$audience]);
    }

    /** @return \Generator<int, array{email:string,name:?string,user_id:?int,contact_id:?int}> */
    public function rows(MailCampaign $c): \Generator
    {
        $seen = [];
        foreach ($this->source($c) as $row) {
            $email = MailHeaderGuard::address($row['email']);
            if (!$email || isset($seen[$email])) continue;
            $seen[$email] = true;
            yield ['email' => $email] + $row;
        }
    }

    public function count(MailCampaign $c): int
    {
        $n = 0;
        foreach ($this->rows($c) as $_) $n++;
        return $n;
    }

    protected function source(MailCampaign $c): \Generator
    {
        $needsConsent = ConsentService::requiresConsent($c->kind);
        $purpose = $c->purpose;
        $size = (int) config('mailing.chunk_size', 500);

        if ($c->environment === 'public' && $c->audience_type === 'public_subscribers') {
            $q = MailSubscription::query()->where('purpose', $purpose)->where('status', 'granted')
                ->whereNotNull('contact_id')
                ->whereHas('contact', fn ($w) => $w->where('status', 'active'))->with('contact');
            foreach ($q->lazyById($size)->chunk($size) as $chunk) {
                $eligible = $this->eligibleEmails($chunk->pluck('email')->all());
                foreach ($chunk as $s) {
                    if (isset($eligible[strtolower($s->email)])) continue;          // verified investors never receive public mailing
                    yield ['email' => $s->email, 'name' => $s->contact?->name, 'user_id' => null, 'contact_id' => $s->contact_id];
                }
            }
            return;
        }

        if ($c->environment === 'public' && $c->audience_type === 'registered_non_investors') {
            $q = User::query()->where('role', 'investor')->where('status', 'active')->whereNotNull('email_verified_at')
                ->where(fn ($w) => $w->where('kyc_verified', false)->orWhereNull('kyc_verified'));
            if ($needsConsent) $q->whereExists($this->grantedExists($purpose));
            foreach ($q->lazyById($size) as $u) {
                yield ['email' => $u->email, 'name' => $u->name ?? $u->full_name, 'user_id' => $u->id, 'contact_id' => null];
            }
            return;
        }

        if ($c->environment === 'investor' && in_array($c->audience_type, ['verified_investors', 'verified_investors_by_rank'], true)) {
            $q = InvestorEligibility::scope(User::query());
            if ($c->audience_type === 'verified_investors_by_rank') {
                $ids = array_values(array_filter(array_map('intval', (array) ($c->audience_filters['rank_ids'] ?? []))));
                if (!$ids) return;                                                 // a rank audience with no ranks selected is empty, never "everyone"
                $q->whereIn('investor_rank_id', $ids);
            }
            if ($needsConsent) $q->whereExists($this->grantedExists($purpose));
            foreach ($q->lazyById($size) as $u) {
                yield ['email' => $u->email, 'name' => $u->name ?? $u->full_name, 'user_id' => $u->id, 'contact_id' => null];
            }
            return;
        }
        // Unknown / mismatched environment + audience: yields nothing (fail closed).
    }

    protected function grantedExists(?string $purpose): \Closure
    {
        return function ($sub) use ($purpose) {
            $sub->select(DB::raw(1))->from('mail_subscriptions')
                ->whereRaw('mail_subscriptions.email = LOWER(users.email)')   // subscriptions are stored lower-case
                ->where('mail_subscriptions.purpose', $purpose ?? '')
                ->where('mail_subscriptions.status', 'granted');
        };
    }

    /** @return array<string,true> lower-case emails in $emails that belong to verified, eligible investors */
    protected function eligibleEmails(array $emails): array
    {
        if (!$emails) return [];
        $lower = array_map('strtolower', $emails);
        return InvestorEligibility::scope(User::query())->whereIn(DB::raw('LOWER(email)'), $lower)->pluck('email')
            ->mapWithKeys(fn ($e) => [strtolower($e) => true])->all();
    }
}
