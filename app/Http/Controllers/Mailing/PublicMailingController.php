<?php
// TASK 3 — PUBLIC endpoints (no authentication): contact form, newsletter sign-up, confirm and unsubscribe pages.
// Never returns investor data; identical responses whether or not an address is already known.
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\PublicMailingService;
use Illuminate\Http\Request;

class PublicMailingController extends Controller
{
    use Concerns;

    public function __construct(protected PublicMailingService $service) {}

    // POST /api/public/mailing/contact
    public function contact(Request $r)
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:120', $this->noInjection()],
            'email' => ['required', 'email:rfc', 'max:190', $this->noInjection()],
            'subject' => ['nullable', 'string', 'max:150', $this->noInjection()],
            'message' => ['required', 'string', 'min:10', 'max:' . (int) config('mailing.contact.max_message', 5000)],
            'subscribe_newsletter' => ['sometimes', 'boolean'],
            'website' => ['nullable', 'string', 'max:255'],        // honeypot
        ]);
        $this->service->submitEnquiry($d, $r);
        return response()->json(['success' => true, 'message' => 'Thank you. Your message has been received.'], 202);
    }

    // POST /api/public/mailing/subscribe
    public function subscribe(Request $r)
    {
        $d = $r->validate([
            'email' => ['required', 'email:rfc', 'max:190', $this->noInjection()], 'name' => ['nullable', 'string', 'max:120', $this->noInjection()],
            'purposes' => ['required', 'array', 'min:1', 'max:2'], 'purposes.*' => ['in:' . implode(',', ConsentService::PUBLIC_PURPOSES)],
            'website' => ['nullable', 'string', 'max:255'],
        ]);
        if (empty($d['website'])) $this->service->subscribe($d['email'], $d['name'] ?? null, $d['purposes'], $r);
        return response()->json(['success' => true, 'message' => 'If the address is valid, a confirmation email is on its way. You are subscribed only after you confirm.'], 202);
    }

    // GET shows a confirmation button (a GET never changes state, so link-scanners cannot unsubscribe / subscribe anyone).
    public function unsubscribePage(Request $r, string $ref, string $purpose)
    {
        return $this->page('Unsubscribe', 'Unsubscribe from ' . $this->label($purpose) . '?',
            'You will stop receiving these optional emails. Essential account and security notices are not affected.', 'Unsubscribe', $r->fullUrl());
    }

    // POST — also the RFC 8058 one-click target (List-Unsubscribe-Post)
    public function unsubscribe(Request $r, string $ref, string $purpose)
    {
        $this->service->unsubscribe($ref, $purpose, $r);
        return $this->page('Unsubscribed', 'You have been unsubscribed.', 'You will no longer receive ' . $this->label($purpose) . '. Essential account and security notices are not affected.');
    }

    public function confirmPage(Request $r, string $ref, string $purpose)
    {
        return $this->page('Confirm subscription', 'Confirm your subscription to ' . $this->label($purpose), 'Press the button to confirm that you want to receive these emails.', 'Confirm subscription', $r->fullUrl());
    }

    public function confirm(Request $r, string $ref, string $purpose)
    {
        $ok = $this->service->confirm($ref, $purpose, $r);
        return $this->page($ok ? 'Subscribed' : 'Link not valid', $ok ? 'Your subscription is confirmed.' : 'This link is no longer valid.', $ok ? 'You can unsubscribe at any time using the link in every email.' : 'Please sign up again from our website.');
    }

    protected function label(string $purpose): string
    {
        return match ($purpose) {
            'public_newsletter', 'investor_newsletter' => 'the SSI newsletter',
            'public_announcements', 'investor_announcements' => 'SSI announcements',
            default => 'these emails',
        };
    }

    protected function page(string $title, string $heading, string $text, ?string $button = null, ?string $action = null)
    {
        $form = $button ? '<form method="POST" action="' . e($action) . '"><button type="submit">' . e($button) . '</button></form>' : '';
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' — Smart System Investment</title>'
            . '<style>body{font-family:system-ui,Arial,sans-serif;background:#F1F5F9;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . '.c{background:#fff;max-width:440px;margin:16px;padding:32px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.06)}h1{font-size:20px;color:#1A3A8F;margin:0 0 12px}p{color:#334155;line-height:1.6}'
            . 'button{background:#2552C4;color:#fff;border:0;border-radius:8px;padding:12px 20px;font-size:15px;cursor:pointer}</style></head><body><div class="c"><h1>' . e($heading) . '</h1><p>' . e($text) . '</p>' . $form . '</div></body></html>';
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
