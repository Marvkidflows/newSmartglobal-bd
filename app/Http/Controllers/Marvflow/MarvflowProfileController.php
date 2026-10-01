<?php
// LOCATION: app/Http/Controllers/Marvflow/MarvflowProfileController.php
//
// Mirrors FinancialProfileController exactly — small and self-contained,
// only ever touches the logged-in user's own row, no route parameter.

namespace App\Http\Controllers\Marvflow;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class MarvflowProfileController extends Controller
{
    // GET /marvflow/profile
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

    // POST /marvflow/profile/password
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
