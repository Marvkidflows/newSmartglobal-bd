<?php
// TASK 3 — consent records, unsubscribe processing and signed links.
//
// * Marketing-type sends (newsletter / announcement) need a CURRENT 'granted' subscription for the exact purpose.
// * Essential / transactional / enquiry / support messages never consult marketing consent, so unsubscribing can never switch off
//   account or security notifications.
// * Every change appends a timestamped ledger row (mail_consents) AND updates the current state (mail_subscriptions).
namespace App\Services\Mailing;

use App\Models\Mailing\MailConsent;
use App\Models\Mailing\MailContact;
use App\Models\Mailing\MailSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ConsentService
{
    public const PUBLIC_PURPOSES   = ['public_newsletter', 'public_announcements'];
    public const INVESTOR_PURPOSES = ['investor_newsletter', 'investor_announcements'];
    public const CONSENT_KINDS     = ['newsletter', 'announcement'];            // everything else is not consent-based

    public static function purposesFor(string $environment): array
    {
        return $environment === 'investor' ? self::INVESTOR_PURPOSES : self::PUBLIC_PURPOSES;
    }

    public static function environmentOf(string $purpose): ?string
    {
        return in_array($purpose, self::PUBLIC_PURPOSES, true) ? 'public' : (in_array($purpose, self::INVESTOR_PURPOSES, true) ? 'investor' : null);
    }

    public static function purposeFor(string $environment, string $kind): ?string
    {
        return match ($kind) {
            'newsletter'   => $environment . '_newsletter',
            'announcement' => $environment . '_announcements',
            default        => null,
        };
    }

    public static function requiresConsent(string $kind): bool
    {
        return in_array($kind, self::CONSENT_KINDS, true);
    }

    public function isGranted(string $email, ?string $purpose): bool
    {
        if (!$purpose) return false;
        return MailSubscription::where('email', strtolower($email))->where('purpose', $purpose)->where('status', 'granted')->exists();
    }

    /** Public double opt-in step 1: recorded as a request; nothing is sent to this person until they confirm. */
    public function request(string $email, string $purpose, string $source, ?MailContact $contact = null, ?User $user = null, ?Request $req = null): void
    {
        $this->write($email, $purpose, 'pending', 'requested', $source, $contact, $user, $req, onlyIfNotGranted: true);
    }

    public function grant(string $email, string $purpose, string $source, ?MailContact $contact = null, ?User $user = null, ?Request $req = null): void
    {
        $this->write($email, $purpose, 'granted', 'granted', $source, $contact, $user, $req);
    }

    public function withdraw(string $email, string $purpose, string $source, ?MailContact $contact = null, ?User $user = null, ?Request $req = null): void
    {
        $this->write($email, $purpose, 'withdrawn', 'withdrawn', $source, $contact, $user, $req);
    }

    public function withdrawAll(string $email, string $source, ?Request $req = null): void
    {
        $email = strtolower($email);
        $contact = MailContact::where('email', $email)->first();
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        foreach (array_merge(self::PUBLIC_PURPOSES, self::INVESTOR_PURPOSES) as $p) {
            if (MailSubscription::where('email', $email)->where('purpose', $p)->whereIn('status', ['granted', 'pending'])->exists()) {
                $this->withdraw($email, $p, $source, $contact, $user, $req);
            }
        }
    }

    protected function write(string $email, string $purpose, string $status, string $action, string $source, ?MailContact $contact, ?User $user, ?Request $req, bool $onlyIfNotGranted = false): void
    {
        if (!in_array($purpose, array_merge(self::PUBLIC_PURPOSES, self::INVESTOR_PURPOSES), true)) {
            throw new \InvalidArgumentException('Unknown consent purpose.');
        }
        $email = strtolower($email);

        DB::transaction(function () use ($email, $purpose, $status, $action, $source, $contact, $user, $req, $onlyIfNotGranted) {
            $sub = MailSubscription::where('email', $email)->where('purpose', $purpose)->lockForUpdate()->first();
            if ($onlyIfNotGranted && $sub && $sub->status === 'granted') return;   // never downgrade an existing grant to "pending"

            MailSubscription::updateOrCreate(
                ['email' => $email, 'purpose' => $purpose],
                ['status' => $status, 'contact_id' => $contact?->id ?? $sub?->contact_id, 'user_id' => $user?->id ?? $sub?->user_id, 'changed_at' => now()]
            );
            MailConsent::create([
                'email' => $email, 'contact_id' => $contact?->id, 'user_id' => $user?->id, 'purpose' => $purpose, 'action' => $action,
                'source' => $source, 'policy_version' => config('mailing.consent_policy_version'),
                'ip_hash' => $req ? $this->hashIp($req->ip()) : null,
                'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        });
    }

    public function hashIp(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }

    // ── Signed links ────────────────────────────────────────────────────────────────────────────────────────────────────────
    // ref = "c-<contact uuid>" (public contact) or "u-<user id>" (investor). The signature prevents tampering; the e-mail address is never
    // placed in a URL.

    public static function ref(?MailContact $contact, ?User $user): ?string
    {
        if ($contact) return 'c-' . $contact->public_id;
        if ($user) return 'u-' . $user->id;
        return null;
    }

    public function unsubscribeUrl(?MailContact $contact, ?User $user, string $purpose): ?string
    {
        $ref = self::ref($contact, $user);
        return $ref ? URL::signedRoute('mailing.unsubscribe', ['ref' => $ref, 'purpose' => $purpose]) : null;   // no expiry: unsubscribe links must keep working
    }

    public function confirmUrl(MailContact $contact, string $purpose): string
    {
        return URL::temporarySignedRoute('mailing.confirm', now()->addDays(7), ['ref' => 'c-' . $contact->public_id, 'purpose' => $purpose]);
    }

    /** @return array{0: ?MailContact, 1: ?User, 2: ?string} */
    public function resolveRef(string $ref): array
    {
        if (str_starts_with($ref, 'c-')) {
            $c = MailContact::where('public_id', substr($ref, 2))->first();
            return [$c, null, $c?->email];
        }
        if (str_starts_with($ref, 'u-') && ctype_digit(substr($ref, 2))) {
            $u = User::where('role', 'investor')->find((int) substr($ref, 2));
            return [null, $u, $u ? strtolower($u->email) : null];
        }
        return [null, null, null];
    }
}
