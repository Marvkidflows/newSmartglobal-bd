<?php
// LOCATION: app/Http/Controllers/Registration/RegistrationTrackingController.php
//
// Task 2 — PUBLIC endpoints used by the registration page. Privacy by design:
//   * tracking is OFF unless explicitly enabled in config (pending Management/legal approval of the disclosure);
//   * even when enabled, nothing is stored unless the visitor sent consent=true;
//   * only a hash of a random client-generated token is kept — no IP, user-agent or fingerprint.

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Services\Registration\RegistrationTracker;
use Illuminate\Http\Request;

class RegistrationTrackingController extends Controller
{
    // GET /api/public/registration-tracking/notice — lets the page decide whether to show the consent notice at all.
    public function notice()
    {
        return response()->json([
            'enabled' => (bool) config('registration.visit_tracking_enabled'),
            'version' => config('registration.tracking_notice_version'),
        ]);
    }

    // POST /api/public/registration-tracking/visit
    public function visit(Request $request, RegistrationTracker $tracker)
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'regex:' . RegistrationTracker::VISITOR_TOKEN_RULE],
            'consent'       => ['required', 'boolean'],
        ]);

        try {
            $visit = $tracker->recordVisit($data['visitor_token'], (bool) $data['consent']);
        } catch (\Throwable $e) {
            \Log::warning('Registration visit tracking failed: ' . $e->getMessage());
            $visit = null;   // tracking must never surface an error to a visitor
        }

        return response()->json(['tracked' => (bool) $visit], 202);
    }
}
