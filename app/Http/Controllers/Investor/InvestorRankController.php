<?php
// LOCATION: app/Http/Controllers/Investor/InvestorRankController.php
// READ-ONLY: an investor can see their own rank; nothing here can change it.
// The frontend re-fetches this to refresh the badge without a re-login.

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InvestorRankController extends Controller
{
    // GET /investor-investment/rank
    public function show(Request $request)
    {
        $user = $request->user()->load('investorRank');

        return response()->json([
            'rank'            => $user->rankBadge(),
            'account_id'      => $user->account_id,
            'rank_changed_at' => $user->rank_changed_at?->toIso8601String(),
        ]);
    }
}
