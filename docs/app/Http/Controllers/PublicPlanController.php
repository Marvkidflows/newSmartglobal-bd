<?php
// LOCATION: app/Http/Controllers/PublicPlanController.php
//
// Phase 5/8 QA finding: the public marketing pages (homepage, /plans) had
// hardcoded fictional plan data — different names, different minimums,
// different rates, and a different return structure ("Monthly" recurring
// vs the real one-time profit_percentage over duration_months) than what
// admins actually configure in InvestmentPlan. A visitor could register
// expecting one set of terms and find completely different real plans
// after logging in.
//
// This endpoint is the fix: it exposes the SAME live-configured plans
// investors see (via InvestorInvestmentController::getPlans) to the
// logged-out public pages too, so marketing copy can never drift from
// reality again. Public-safe subset only — no investment_accounts_count,
// no admin-only fields.

namespace App\Http\Controllers;

use App\Models\InvestmentPlan;
use Illuminate\Http\Request;

class PublicPlanController extends Controller
{
    // GET /api/plans — public, no auth required
    public function index()
    {
        $plans = InvestmentPlan::where('status', 'active')
            ->orderBy('min_amount')
            ->get()
            ->map(fn (InvestmentPlan $p) => [
                'id'              => $p->id,
                'name'            => $p->name,
                'description'     => $p->description,
                'min_amount'      => (float) $p->min_amount,
                'max_amount'      => $p->max_amount ? (float) $p->max_amount : null,
                'profit_percent'  => (float) ($p->profit_percentage ?? $p->profit_percent ?? 0),
                'duration_days'   => $p->duration_days ?? (($p->duration_months ?? 1) * 30),
                'duration_months' => $p->duration_months,
                'is_featured'     => (bool) ($p->is_featured ?? false),
            ]);

        return response()->json(['plans' => $plans]);
    }
}
