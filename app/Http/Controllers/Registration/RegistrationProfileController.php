<?php
// LOCATION: app/Http/Controllers/Registration/RegistrationProfileController.php
// Registration staff change THEIR OWN password. Only ever touches the logged-in user's row (no route parameter). Stricter than the MarvFlow/Financial
// equivalents: throttled, new password must differ, and every OTHER session token is revoked so a stolen session does not survive the change.
namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\RegistrationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegistrationProfileController extends Controller
{
    // GET /api/registration/profile
    public function show(Request $request)
    {
        $u = $request->user();
        return response()->json(['user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role]]);
    }

    // POST /api/registration/profile/password
    public function changePassword(Request $request)
    {
        $v = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => array_merge(['required', 'confirmed', 'different:current_password'], \App\Support\PasswordPolicy::rules()),   // D-12
        ]);
        $user = $request->user();
        if (!Hash::check($v['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }
        $user->forceFill(['password' => Hash::make($v['new_password'])])->save();
        \App\Support\AuthTokens::revokeOthers($user);   // D-02: same behaviour as before, now shared
        RegistrationEvent::record('staff_password_changed', null, $user);
        return response()->json(['message' => 'Password updated successfully. Other sessions have been signed out.']);
    }
}
