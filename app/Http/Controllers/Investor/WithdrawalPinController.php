<?php
// LOCATION: app/Http/Controllers/Investor/WithdrawalPinController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class WithdrawalPinController extends Controller
{
    // GET /investor-investment/investor/withdrawal-pin/status
    public function status(Request $request)
    {
        $user = Auth::user();
        return response()->json(['has_pin' => (bool) $user->withdrawal_pin]);
    }

    // POST /investor-investment/investor/withdrawal-pin
    public function store(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'withdrawal_pin'              => ['required', 'digits:4', 'confirmed'],
            'withdrawal_pin_confirmation' => ['required'],
        ];

        // If a PIN already exists, the current one must be supplied to change it.
        if ($user->withdrawal_pin) {
            $rules['current_pin'] = ['required', 'digits:4'];
        }

        $validated = $request->validate($rules);

        if ($user->withdrawal_pin) {
            if (!Hash::check($validated['current_pin'], $user->withdrawal_pin)) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Current PIN is incorrect.'], 422);
                }
                return back()->withErrors(['current_pin' => 'Current PIN is incorrect.']);
            }
        }

        $user->withdrawal_pin = Hash::make($validated['withdrawal_pin']);
        $user->save();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Withdrawal PIN saved successfully.']);
        }
        return back()->with('success', 'Withdrawal PIN saved.');
    }
}