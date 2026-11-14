<?php
// TASK 3 — what a verified investor can see of their OWN investor mailing: history and optional-email preferences.
// Unsubscribing from optional mail never affects essential account / security notices (those never consult these preferences).
namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\Mailing\MailMessage;
use App\Models\Mailing\MailSubscription;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\InvestorEligibility;
use Illuminate\Http\Request;

class InvestorMailingController extends Controller
{
    protected function own(Request $r)
    {
        return MailMessage::where('environment', 'investor')->where('user_id', $r->user()->id)->where('direction', 'outbound')->whereIn('status', ['sent', 'delivered']);
    }

    // GET /api/investor/mailing/history
    public function history(Request $r)
    {
        abort_unless(InvestorEligibility::isEligible($r->user()), 403);
        return response()->json($this->own($r)->latest('id')->paginate(25, ['id', 'subject', 'kind', 'sent_at'])->through(fn ($m) => $m->only(['id', 'subject', 'kind', 'sent_at'])));
    }

    // GET /api/investor/mailing/history/{id}
    public function show(Request $r, int $id)
    {
        abort_unless(InvestorEligibility::isEligible($r->user()), 403);
        $m = $this->own($r)->findOrFail($id);                    // another investor's message id simply 404s
        return response()->json(['message' => $m->only(['id', 'subject', 'kind', 'sent_at']) + ['body_html' => $m->body_html]]);
    }

    // GET /api/investor/mailing/preferences
    public function preferences(Request $r)
    {
        $subs = MailSubscription::where('email', strtolower($r->user()->email))->whereIn('purpose', ConsentService::INVESTOR_PURPOSES)->pluck('status', 'purpose');
        return response()->json([
            'optional' => collect(ConsentService::INVESTOR_PURPOSES)->mapWithKeys(fn ($p) => [$p => ($subs[$p] ?? 'none') === 'granted'])->all(),
            'essential_notice' => 'Essential account, security and service notices are always delivered and cannot be switched off here.',
        ]);
    }

    // PUT /api/investor/mailing/preferences { investor_newsletter: bool, investor_announcements: bool }
    public function update(Request $r, ConsentService $consent)
    {
        abort_unless(InvestorEligibility::isEligible($r->user()), 403);
        $d = $r->validate(['investor_newsletter' => ['sometimes', 'boolean'], 'investor_announcements' => ['sometimes', 'boolean']]);
        foreach ($d as $purpose => $on) {
            $on ? $consent->grant($r->user()->email, $purpose, 'investor_preferences', null, $r->user(), $r)
                : $consent->withdraw($r->user()->email, $purpose, 'investor_preferences', null, $r->user(), $r);
        }
        return $this->preferences($r);
    }
}
