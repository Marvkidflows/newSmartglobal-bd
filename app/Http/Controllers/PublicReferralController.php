<?php
// Task 5: public, throttled, write-only "referral link visited" beacon. Reveals nothing about the referrer and changes no attribution -
// attribution is only ever written when an account is created (RegisterController).
namespace App\Http\Controllers;

use App\Services\Task5\ReferralService;
use Illuminate\Http\Request;

class PublicReferralController extends Controller
{
    public function visit(Request $request, ReferralService $svc)
    {
        $v = $request->validate(['code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/']]);
        $svc->recordVisit($v['code'], $request);
        return response()->json(['success' => true]);          // identical answer whether or not the code exists (no code enumeration)
    }
}
