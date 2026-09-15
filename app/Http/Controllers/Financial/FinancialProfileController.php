<?php
// LOCATION: app/Http/Controllers/Financial/FinancialProfileController.php
//
// Financial Team dashboard — deliberately small and self-contained
// rather than reusing InvestorProfileController (which is gated by
// InvestorMiddleware and handles KYC/investor-specific fields that
// don't apply here). This only ever touches the logged-in user's own
// row — no route parameter, no way to target anyone else's account —
// which is what keeps this safe without needing its own authorization
// checks beyond "is this a financial/admin user" (FinancialMiddleware).

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class FinancialProfileController extends Controller
{
    // GET /financial/profile
    public function show(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ]);
    }

    // POST /financial/profile/password
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'      => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = Auth::user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
