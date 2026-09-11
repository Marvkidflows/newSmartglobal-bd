<?php
// LOCATION: app/Http/Controllers/Investor/InvestorProfileController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Storage;
use App\Services\PhoneNumberService;

class InvestorProfileController extends Controller
{
    protected PhoneNumberService $phoneService;

    public function __construct(PhoneNumberService $phoneService)
    {
        $this->phoneService = $phoneService;
    }

    // GET /investor-investment/investor/profile
    public function show(Request $request)
    {
        $user = Auth::user();

        $data = [
            'user' => [
                'id'             => $user->id,
                'name'           => $user->name ?? $user->full_name,
                'full_name'      => $user->full_name ?? $user->name,
                'email'          => $user->email,
                'phone'          => $user->phone ?? null,
                'country'        => $user->country ?? null,
                'country_code'   => $user->country_code ?? null,
                'country_iso2'   => $user->country_iso2 ?? null,
                'address'        => $user->address ?? null,
                'city'           => $user->city ?? null,
                'state'          => $user->state ?? null,
                'postal_code'    => $user->postal_code ?? null,
                'date_of_birth'  => $user->date_of_birth ?? null,
                'balance'        => (float) ($user->balance ?? 0),
                'referral_code'  => $user->referral_code ?? null,
                'status'         => $user->status ?? 'active',
                'role'           => $user->role,
                'created_at'     => $user->created_at->toDateString(),
                'profile_photo'  => $user->profile_photo_url
                                    ?? $user->avatar
                                    ?? null,

                // ── KYC ──────────────────────────────────────────────────
                'id_type'              => $user->id_type ?? null,
                'id_number'            => $user->id_number ?? null,
                'has_id_document'      => (bool) $user->id_document_path,
                'has_selfie'           => (bool) $user->selfie_path,
                'kyc_status'           => $user->kyc_status_safe,
                'kyc_verified'         => (bool) $user->kyc_verified,
                'kyc_verified_at'      => optional($user->kyc_verified_at)->toDateTimeString(),
                'kyc_rejection_reason' => $user->kyc_rejection_reason ?? null,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }
        return view('investor.profile.show', $data);
    }

    // GET /investor-investment/investor/profile/edit
    public function edit(Request $request)
    {
        return $this->show($request);
    }

    // PUT /investor-investment/investor/profile
    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'           => ['sometimes', 'string', 'max:255'],
            'full_name'      => ['sometimes', 'string', 'max:255'],
            'email'          => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'phone'          => ['sometimes', 'string', 'max:20'],
            'country_iso2'   => ['required_with:phone', 'string', 'size:2'],
            'address'        => ['sometimes', 'string', 'max:500'],
            'city'           => ['sometimes', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:20'],
            'date_of_birth'  => ['nullable', 'date'],
            'current_password'   => ['sometimes', 'string'],
            'new_password'       => ['sometimes', 'min:8', 'confirmed'],
        ]);

        // Handle password change
        if ($request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Current password is incorrect.'], 422);
                }
                return back()->withErrors(['current_password' => 'Current password is incorrect.']);
            }
            $validated['password'] = Hash::make($request->new_password);
        }

        // Remove password fields from validated before updating
        unset($validated['current_password'], $validated['new_password'], $validated['new_password_confirmation']);

        // Real phone/country validation — same rule as registration
        // (Phase 2 fix). Dial code and display country name are derived
        // server-side from the ISO2 code, never trusted from the client.
        if (isset($validated['phone'])) {
            $phone = $this->phoneService->validateAndNormalize(
                $validated['phone'],
                $validated['country_iso2']
            );

            if ($this->phoneService->isDuplicate($phone['phone_e164'], $user->id)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'This phone number is already registered to another account.',
                        'errors'  => ['phone' => ['This phone number is already registered to another account.']],
                    ], 422);
                }
                return back()->withErrors(['phone' => 'This phone number is already registered to another account.']);
            }

            $validated['phone']        = $phone['phone_e164'];
            $validated['country']      = $phone['country_name'];
            $validated['country_code'] = $phone['country_code'];
            $validated['country_iso2'] = $phone['country_iso2'];
        } else {
            unset($validated['country_iso2']);
        }

        // Sync name / full_name
        if (isset($validated['full_name'])) {
            $validated['name'] = $validated['full_name'];
        } elseif (isset($validated['name'])) {
            $validated['full_name'] = $validated['name'];
        }

        $user->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Profile updated successfully.',
                'user'    => [
                    'id'    => $user->id,
                    'name'  => $user->fresh()->name ?? $user->full_name,
                    'email' => $user->fresh()->email,
                ],
            ]);
        }

        return back()->with('success', 'Profile updated.');
    }

    // POST /investor-investment/investor/profile/kyc
   

public function submitKyc(Request $request)
{
    $user = Auth::user();

    $validated = $request->validate([
        'id_type'      => 'required|in:national_id,passport,drivers_license',
        'id_number'    => 'required|string|max:100',
        'id_document'  => 'required|image|max:5120',
        'selfie'       => 'required|image|max:5120',
    ]);

    $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));

    $document = $cloudinary->uploadApi()->upload(
        $request->file('id_document')->getRealPath(),
        [
            'folder' => 'kyc-documents',
        ]
    );

    $selfie = $cloudinary->uploadApi()->upload(
        $request->file('selfie')->getRealPath(),
        [
            'folder' => 'kyc-selfies',
        ]
    );

    $user->update([
        'id_type'              => $validated['id_type'],
        'id_number'            => $validated['id_number'],
        'id_document_path'     => $document['secure_url'],
        'selfie_path'          => $selfie['secure_url'],
        'kyc_status'           => 'pending',
        'kyc_verified'         => false,
        'kyc_verified_at'      => null,
        'kyc_rejection_reason' => null,
    ]);

    return response()->json([
        'message' => 'Verification documents submitted successfully.',
        'kyc_status' => 'pending',
    ]);
}
}