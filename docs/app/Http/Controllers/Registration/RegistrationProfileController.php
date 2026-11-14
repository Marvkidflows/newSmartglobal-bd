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
            'new_password'     => ['required', 'string', 'min:8', 'max:128', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();
        if (!Hash::check($v['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }
        $user->forceFill(['password' => Hash::make($v['new_password'])])->save();
        $current = $user->currentAccessToken();
        $keep    = $current instanceof \Laravel\Sanctum\PersonalAccessToken ? $current->getKey() : null;   // transient/session auth has no token row to keep
        $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
        RegistrationEvent::record('staff_password_changed', null, $user);
        return response()->json(['message' => 'Password updated successfully. Other sessions have been signed out.']);
    }
}
