<?php
// LOCATION: app/Http/Controllers/PrivacyPolicyController.php

namespace App\Http\Controllers;

use App\Services\PrivacyPolicyService;

class PrivacyPolicyController extends Controller
{
    // GET /api/privacy-policy — public. Returns only policy metadata
    // (version / approval flag / whether acknowledgement is mandatory).
    // No user data of any kind.
    public function show(PrivacyPolicyService $policy)
    {
        return response()->json($policy->meta());
    }
}
