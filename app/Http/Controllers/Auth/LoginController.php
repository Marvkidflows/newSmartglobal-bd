<?php
// LOCATION: app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Invalid email or password.'
                ], 401);
            }
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // ── DEACTIVATED — block login entirely ────────────────────────────────
        if ($user->status === 'deactivated') {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status'  => 'deactivated',
                    'message' => 'Your account has been deactivated. Please contact support.',
                ], 403);
            }
            return back()->withErrors([
                'email' => 'Your account has been deactivated. Please contact support.',
            ])->onlyInput('email');
        }

        // ── REGISTRATION INCOMPLETE — investors only; admin, financial,
        // and MarvFlow accounts are staff/team accounts created directly
        // (no self-service signup, no registration wizard), so they never
        // go through this check. Without marvflow_member/marvflow_lead
        // listed here, a freshly created MarvFlow account would be
        // permanently blocked at login unless registration_completed was
        // manually set true for it — this makes that unnecessary.
        $staffRoles = ['admin', 'financial', 'marvflow_member', 'marvflow_lead'];
        if (!in_array($user->role, $staffRoles, true) && !$user->registration_completed) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success'        => false,
                    'status'         => 'registration_incomplete',
                    'current_stage'  => $user->registration_stage,
                    'message'        => 'Please finish creating your account before logging in.',
                ], 403);
            }
            return back()->withErrors([
                'email' => 'Please finish creating your account before logging in.',
            ])->onlyInput('email');
        }

        // Suspended and frozen users CAN log in — the frontend handles the UI
        // restriction, and the middleware enforces backend protection.

        $token = $user->createToken('auth-token')->plainTextToken;

        if ($request->expectsJson()) {
            return response()->json([
                'token' => $token,
                'user'  => [
                    'id'             => $user->id,
                    'name'           => $user->name ?? $user->full_name,
                    'email'          => $user->email,
                    'role'           => $user->role,
                    'balance'        => (float) ($user->balance ?? 0),
                    'referral_code'  => $user->referral_code ?? null,
                    'phone'          => $user->phone ?? null,
                    'country'        => $user->country ?? null,
                    'status'         => $user->status ?? 'active',
                    'created_at'     => $user->created_at,
                    // The block above already guarantees registration is
                    // complete by the time we get here, but the frontend's
                    // isRegistrationComplete() check still needs this field
                    // present and >= 4 — without it, every normal login
                    // would get bounced back to /register.
                    'registration_stage' => $user->role === 'admin' ? 4 : $user->registration_stage,
                ],
                'message' => 'Login successful.',
            ]);
        }

        Auth::login($user);
        if ($user->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }
        return redirect()->route('investor-investment.dashboard');
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out successfully.']);
        }
        return redirect()->route('home');
    }
}