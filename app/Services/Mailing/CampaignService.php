<?php
// TASK 3 — campaign lifecycle: validation of content, create/update, schedule, send, cancel, audience expansion and completion.
//
// Duplicate-send protection has three layers: (1) the draft/scheduled → queued status change is one atomic UPDATE, so two concurrent
// "send" requests cannot both win; (2) every recipient row carries a UNIQUE idempotency key (campaign + address); (3) MessageSender claims
// each row with an atomic queued → sending UPDATE before it touches the mail transport.
namespace App\Services\Mailing;

use App\Jobs\SendMailingMessageJob;
use App\Jobs\StartMailingCampaignJob;
use App\Models\Mailing\MailCampaign;
use App\Models\Mailing\MailMessage;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    /** Merge tags permitted per environment. Balances, account numbers and other confidential fields are NOT available in any campaign. */
    public const MERGE_TAGS = [
        'public'   => ['first_name', 'unsubscribe_url'],
        'investor' => ['first_name', 'rank', 'unsubscribe_url'],
    ];

    public function __construct(protected RichTextSanitizer $sanitizer, protected AudienceResolver $audiences) {}

    /** @return array{subject:string, body_html:string} */
    public function cleanContent(string $environment, string $subject, string $bodyHtml): array
    {
        if (MailHeaderGuard::hasInjection($subject)) {
            throw ValidationException::withMessages(['subject' => 'The subject may not contain line breaks.']);
        }
        $subject = MailHeaderGuard::text($subject);
        $body = $this->sanitizer->sanitizeEmail($bodyHtml);
        if ($subject === '') throw ValidationException::withMessages(['subject' => 'A subject is required.']);
        if ($body === '' || strlen($body) > (int) config('mailing.max_body_bytes')) {
            throw ValidationException::withMessages(['body_html' => 'The message body is empty, too large, or contains no permitted content.']);
        }

        $allowed = self::MERGE_TAGS[$environment] ?? [];
        if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $subject . ' ' . $body, $m)) {
            $bad = array_values(array_unique(array_diff(array_map('strtolower', $m[1]), $allowed)));
            if ($bad) {
                throw ValidationException::withMessages(['body_html' => 'Merge tag(s) not available in ' . $environment . ' mailing: ' . implode(', ', $bad)]);
            }
        }
        if ($environment === 'public' && preg_match('/' . preg_quote((string) config('ranks.account_prefix', 'INVSTR-'), '/') . '\d/i', $subject . ' ' . $body)) {
            throw ValidationException::withMessages(['body_html' => 'Investor account references may not appear in public mailing.']);
        }
        return ['subject' => $subject, 'body_html' => $body];
    }

    public function create(User $actor, string $environment, array $data, ?Request $req = null): MailCampaign
    {
        if (!empty($data['client_token']) && ($existing = MailCampaign::where('client_token', $data['client_token'])->first())) {
            return $existing;                                                       // double-submitted create → same campaign, not a second one
        }
        $attrs = $this->normalise($environment, $data);
        $attrs += ['environment' => $environment, 'status' => 'draft', 'created_by' => $actor->id, 'client_token' => $data['client_token'] ?? null];

        $campaign = DB::transaction(fn () => MailCampaign::create($attrs));
        MailingAudit::record($actor, 'campaign.created', $environment, $campaign, ['name' => $campaign->name, 'kind' => $campaign->kind, 'audience' => $campaign->audience_type], $req);
        return $campaign;
    }

    public function update(User $actor, MailCampaign $campaign, array $data, ?Request $req = null): MailCampaign
    {
        if (!$campaign->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft or scheduled campaigns can be edited.']);
        }
        $attrs = $this->normalise($campaign->environment, $data + $campaign->only(['name', 'subject', 'body_html', 'kind', 'audience_type', 'audience_filters', 'template_id', 'department_id']));
        $campaign->update($attrs);
        MailingAudit::record($actor, 'campaign.updated', $campaign->environment, $campaign, ['name' => $campaign->name], $req);
        return $campaign->refresh();
    }

    protected function normalise(string $environment, array $d): array
    {
        $kind = $d['kind'];
        if ($environment === 'public' && $kind === 'essential') {
            throw ValidationException::withMessages(['kind' => 'Essential notices are only available in investor mailing.']);
        }
        if (!AudienceResolver::valid($environment, $d['audience_type'])) {
            throw ValidationException::withMessages(['audience_type' => 'This audience is not available in ' . $environment . ' mailing.']);
        }
        $content = $this->cleanContent($environment, (string) $d['subject'], (string) $d['body_html']);

        $filters = [];
        if ($d['audience_type'] === 'verified_investors_by_rank') {
            $filters['rank_ids'] = array_values(array_unique(array_map('intval', (array) ($d['audience_filters']['rank_ids'] ?? []))));
        }
        return [
            'name' => MailHeaderGuard::text($d['name'], 150),
            'subject' => $content['subject'], 'body_html' => $content['body_html'],
            'kind' => $kind, 'purpose' => ConsentService::purposeFor($environment, $kind),   // derived server-side, never trusted from the client
            'audience_type' => $d['audience_type'], 'audience_filters' => $filters ?: null,
            'template_id' => $d['template_id'] ?? null, 'department_id' => $d['department_id'] ?? null,
        ];
    }

    public function schedule(User $actor, MailCampaign $campaign, Carbon $at, ?Request $req = null): MailCampaign
    {
        if ($at->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => 'The scheduled time must be in the future.']);
        }
        $n = MailCampaign::whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'scheduled', 'scheduled_at' => $at, 'updated_at' => now()]);
        if ($n !== 1) throw ValidationException::withMessages(['status' => 'This campaign can no longer be scheduled.']);
        MailingAudit::record($actor, 'campaign.scheduled', $campaign->environment, $campaign, ['scheduled_at' => $at->toIso8601String()], $req);
        return $campaign->refresh();
    }

    /** Atomically claims the campaign and queues expansion. Returns false if somebody else already started / cancelled it. */
    public function sendNow(?User $actor, MailCampaign $campaign, ?Request $req = null): bool
    {
        $n = MailCampaign::whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'queued', 'dispatched_by' => $actor?->id, 'started_at' => now(), 'updated_at' => now()]);
        if ($n !== 1) return false;

        MailingAudit::record($actor, $actor ? 'campaign.sent' : 'campaign.sent_scheduled', $campaign->environment, $campaign, ['audience' => $campaign->audience_type], $req);
        StartMailingCampaignJob::dispatch($campaign->id);
        return true;
    }

    public function cancel(User $actor, MailCampaign $campaign, ?Request $req = null): bool
    {
        $n = MailCampaign::whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled', 'queued', 'sending'])->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        if ($n !== 1) return false;
        MailMessage::where('campaign_id', $campaign->id)->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'campaign_cancelled', 'updated_at' => now()]);
        MailingAudit::record($actor, 'campaign.cancelled', $campaign->environment, $campaign, [], $req);
        return true;
    }

    /** Materialises one message row per recipient (idempotent) and dispatches a send job for each still-queued row. */
    public function expand(int $campaignId): void
    {
        $campaign = MailCampaign::find($campaignId);
        if (!$campaign || !in_array($campaign->status, ['queued', 'sending'], true)) return;

        $chunkSize = (int) config('mailing.chunk_size', 500);
        $buffer = [];
        foreach ($this->audiences->rows($campaign) as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $chunkSize) { $this->insertChunk($campaign, $buffer); $buffer = []; }
        }
        if ($buffer) $this->insertChunk($campaign, $buffer);

        $total = MailMessage::where('campaign_id', $campaign->id)->count();
        MailCampaign::whereKey($campaign->id)->where('status', 'queued')->update(['status' => 'sending', 'recipient_count' => $total, 'updated_at' => now()]);
        $this->finalize($campaign->id);
    }

    protected function insertChunk(MailCampaign $c, array $rows): void
    {
        $now = now();
        $records = [];
        foreach ($rows as $r) {
            $records[] = [
                'environment' => $c->environment, 'direction' => 'outbound', 'kind' => $c->kind, 'purpose' => $c->purpose,
                'campaign_id' => $c->id, 'department_id' => $c->department_id, 'user_id' => $r['user_id'], 'contact_id' => $r['contact_id'],
                'sender_user_id' => $c->dispatched_by, 'recipient_email' => $r['email'], 'recipient_name' => $r['name'] ? mb_substr(MailHeaderGuard::text($r['name'], 120), 0, 120) : null,
                'subject' => $c->subject, 'body_html' => $c->body_html, 'status' => 'queued',
                'idempotency_key' => sha1('campaign:' . $c->id . '|' . $r['email']), 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::transaction(fn () => MailMessage::insertOrIgnore($records));           // re-running expansion can never create a second row for the same address

        $ids = MailMessage::where('campaign_id', $c->id)->whereIn('idempotency_key', array_column($records, 'idempotency_key'))->where('status', 'queued')->pluck('id');
        foreach ($ids as $id) SendMailingMessageJob::dispatch($id);
    }

    /** Called after expansion and after every message outcome; closes the campaign when nothing is pending. */
    public function finalize(int $campaignId): void
    {
        $c = MailCampaign::find($campaignId);
        if (!$c || $c->status !== 'sending') return;
        $by = MailMessage::where('campaign_id', $campaignId)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        if (($by['queued'] ?? 0) + ($by['sending'] ?? 0) > 0) return;

        $sent = ($by['sent'] ?? 0) + ($by['delivered'] ?? 0);
        $failed = ($by['failed'] ?? 0) + ($by['bounced'] ?? 0);
        $status = $failed === 0 ? 'sent' : ($sent > 0 ? 'partial' : 'failed');
        MailCampaign::whereKey($campaignId)->where('status', 'sending')->update([
            'status' => $status, 'sent_count' => $sent, 'failed_count' => $failed, 'skipped_count' => $by['skipped'] ?? 0,
            'completed_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Called by the scheduler command. */
    public function dispatchDue(): int
    {
        $n = 0;
        MailCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('id')->each(function (MailCampaign $c) use (&$n) {
            if ($this->sendNow(null, $c)) $n++;
        });
        return $n;
    }
}
