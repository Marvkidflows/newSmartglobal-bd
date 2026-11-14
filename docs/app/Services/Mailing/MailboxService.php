<?php
// TASK 3 — staff / department mailbox management and the provider connection check.
//
// HONESTY RULE: creating a mailbox record never marks it connected. connection_status becomes 'connected' only when the provider's own
// verified-sender list (Brevo GET /v3/senders, using BREVO_API_KEY from the environment) contains this exact address and reports it
// active. No passwords, API keys or tokens are ever stored per mailbox — one provider key lives in the environment, and each mailbox
// is only an address + display name (so no staff member shares or holds a password for another).
namespace App\Services\Mailing;

use App\Models\Mailing\MailMailbox;
use Illuminate\Support\Facades\Http;

class MailboxService
{
    public function checkConnection(MailMailbox $box): MailMailbox
    {
        $box->last_checked_at = now();

        if ($box->provider !== 'brevo') {
            $box->connection_status = 'not_connected';
            $box->last_check_error = 'No provider configured for this mailbox.';
            $box->sender_verified_at = null;
            return tap($box)->save();
        }
        $key = (string) config('mailing.brevo_api_key');
        if ($key === '') {
            $box->connection_status = 'not_connected';
            $box->last_check_error = 'BREVO_API_KEY is not configured on the server.';
            return tap($box)->save();
        }

        try {
            $res = Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])->timeout(10)->get('https://api.brevo.com/v3/senders');
            if (!$res->successful()) {
                $box->connection_status = 'error';
                $box->last_check_error = 'Provider responded with HTTP ' . $res->status() . '.';
                return tap($box)->save();
            }
            $match = collect($res->json('senders') ?? [])->first(fn ($s) => strtolower((string) ($s['email'] ?? '')) === strtolower($box->address) && ($s['active'] ?? false));
            if ($match) {
                $box->connection_status = 'connected';
                $box->last_check_error = null;
                $box->sender_verified_at = now();
            } else {
                $box->connection_status = 'not_connected';
                $box->last_check_error = 'Address is not an active, verified sender at the provider.';
                $box->sender_verified_at = null;
            }
        } catch (\Throwable $e) {
            $box->connection_status = 'error';
            $box->last_check_error = 'Could not reach the provider.';
        }
        return tap($box)->save();
    }
}
