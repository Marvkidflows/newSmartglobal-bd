<?php
// LOCATION: app/Http/Controllers/Auth/RegisterController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNumberService;
use App\Services\PrivacyPolicyService;
use App\Services\Registration\RegistrationResumeService;
use App\Services\Registration\RegistrationTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    protected OtpService $otpService;
    protected PhoneNumberService $phoneService;
    protected PrivacyPolicyService $privacyPolicy;
    protected RegistrationResumeService $resume;
    protected \App\Services\Registration\RegistrationEmailChallengeService $emailChallenges;

    public function __construct(OtpService $otpService, PhoneNumberService $phoneService, PrivacyPolicyService $privacyPolicy, RegistrationResumeService $resume, \App\Services\Registration\RegistrationEmailChallengeService $emailChallenges)
    {
        $this->resume = $resume;
        $this->emailChallenges = $emailChallenges;
        $this->otpService = $otpService;
        $this->phoneService = $phoneService;
        $this->privacyPolicy = $privacyPolicy;
    }

    /**
     * Per-IP hourly cap on how many times an unauthenticated endpoint may be called. It counts EVERY call (not only "interesting" ones), so the
     * 429 it produces reveals nothing about any email or phone number. Additional to the route-level throttle.
     */
    protected function attemptBudget(Request $request, string $bucket, int $max): void
    {
        $key = 'registration:' . $bucket . ':' . sha1((string) $request->ip());
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, max(1, $max))) {
            abort(response()->json(['success' => false, 'message' => 'Too many attempts. Please try again later.'], 429));
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
    }

    /**
     * Budget for the ONE response that still discloses something: "this phone number belongs to another account". It is only ever produced AFTER the
     * caller has proved control of the email address (a valid emailed code), and it is capped per IP so it cannot be used for bulk probing.
     */
    protected function discloseOrThrottle(Request $request): void
    {
        $this->attemptBudget($request, 'disclose', (int) config('registration.disclosure_limit_per_hour', 20));
    }

    /** Make the response time independent of which case occurred (timing side channel). */
    protected function padResponse(float $started): void
    {
        $floor = max(0, (int) config('registration.min_response_ms', 400)) / 1000;
        $left  = $floor - (microtime(true) - $started);
        if ($left > 0) usleep((int) ($left * 1_000_000));
    }

    // =========================================================================
    // STAGE 1 — validate the form, then prove control of the email. NO account is created here.
    // =========================================================================
    // SECURITY (enumeration closure): this endpoint returns the SAME response (status, body, message, retry interval) whether the email is new, belongs
    // to an unfinished registration, belongs to a completed account, a staff account or an inactive account, and whatever the phone number is.
    //   * new email                  -> a code is emailed (registration_email_challenges); nothing is created
    //   * unfinished investor        -> a resume code is emailed to the address on file (registration_resume_challenges); nothing is changed
    //   * completed/staff/inactive   -> nothing is sent, read or changed
    // All of that work runs AFTER the response has been sent, and the response is padded to a minimum duration, so neither content nor timing
    // distinguishes the cases. The phone number is only format-checked here; whether it is already registered is decided after the email is proven.
    // No Telegram alert, tracker record or referral event is produced here: those happen when the account is actually created.
    public function submitStage1(Request $request)
    {
        $started = microtime(true);
        $this->attemptBudget($request, 'stage1', (int) config('registration.stage1_attempts_per_hour', 30));

        $validated = $request->validate([
            'full_name'    => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255'],
            'country_iso2' => ['required', 'string', 'size:2'],
            'phone'        => ['required', 'string', 'max:20'],
            'password'     => ['required', 'min:8', 'confirmed'],
            'referral_code'=> ['nullable', 'string', 'max:20'],
            'visitor_token'=> ['nullable', 'string', 'max:64'],
        ] + $this->privacyPolicy->rules(), $this->privacyPolicy->messages());

        $this->phoneService->validateAndNormalize($validated['phone'], $validated['country_iso2']);   // FORMAT only; says nothing about existing accounts

        $typed = $validated['email'];
        $ran = false;   // run-once: Laravel's test kernel re-runs earlier requests' terminating callbacks on every later request in the same process
        app()->terminating(function () use ($typed, &$ran) {
            if ($ran) return;
            $ran = true;
            try {
                $this->issueCodeFor($typed);
            } catch (\Throwable $e) {
                \Log::error('Registration code issuing failed: ' . \App\Services\Registration\RegistrationCommunicationService::safeError($e->getMessage()));
            }
        });

        $this->padResponse($started);

        return response()->json([
            'success'               => true,
            'verification_required' => true,
            'email_sent'            => true,
            'retry_after_seconds'   => max(1, (int) config('registration.resume_request_cooldown_sec')),
            'message'               => 'If this email address can be used to register, we have sent a 6-digit verification code to it. Enter the code to continue. Nothing is created until you do.',
        ]);
    }

    /** Runs after the response. Decides silently what (if anything) to send. */
    private function issueCodeFor(string $typedEmail): void
    {
        $existing = User::where('email', $typedEmail)->first();

        if ($existing) {
            if ($this->resume->eligible($existing)) {
                $result = $this->resume->requestCode($existing);
                if ($result['sent']) {
                    $this->track(fn (RegistrationTracker $t) => $t->resumeCodeRequested($existing));
                }
            } elseif ($existing->role === 'investor') {
                $this->track(fn (RegistrationTracker $t) => $t->blockedDuplicate($existing));   // internal record for the Registration Team only
            }
            return;   // completed / staff / inactive: nothing is emailed, read or changed
        }

        $this->emailChallenges->requestCode($typedEmail);
    }

    // =========================================================================
    // VERIFY — prove control of the email, then create the account (new email) or resume it (unfinished investor)
    // POST /api/register/verify   (also reachable as POST /api/register/resume/verify, kept for compatibility)
    // =========================================================================
    // Every failure — unknown email, completed/staff/inactive account, wrong/expired/locked/undelivered code — returns the same 422. The only
    // other answers are format errors (which say nothing about accounts) and, AFTER a correct code, "that phone belongs to someone else".
    public function verify(Request $request)
    {
        $started = microtime(true);
        $this->attemptBudget($request, 'verify', (int) config('registration.verify_attempts_per_hour', 60));

        $validated = $request->validate([
            'email'        => ['required', 'email', 'max:255'],
            'code'         => ['required', 'digits:6'],
            'full_name'    => ['required', 'string', 'max:255'],
            'country_iso2' => ['required', 'string', 'size:2'],
            'phone'        => ['required', 'string', 'max:20'],
            'password'     => ['required', 'min:8', 'confirmed'],
            'referral_code'=> ['nullable', 'string', 'max:20'],
            'visitor_token'=> ['nullable', 'string', 'max:64'],
        ] + $this->privacyPolicy->rules(), $this->privacyPolicy->messages());

        $phone = $this->phoneService->validateAndNormalize($validated['phone'], $validated['country_iso2']);

        $failure = fn () => response()->json([
            'success' => false,
            'message' => 'That code is incorrect or has expired. Request a new code and try again.',
        ], 422);

        $user = User::where('email', $validated['email'])->first();

        if ($user && !$this->resume->eligible($user)) {
            \App\Models\RegistrationResumeChallenge::hashCode($validated['code']);   // same hashing work as a real check
            $response = $failure();
        } elseif ($user) {
            $response = $this->finishResume($request, $validated, $phone, $user, $failure);
        } else {
            $response = $this->finishNew($request, $validated, $phone, $failure);
        }

        $this->padResponse($started);
        return $response;
    }

    /** Unfinished investor registration: unchanged behaviour of the Task 2 resume (atomic verify + apply). */
    private function finishResume(Request $request, array $validated, array $phone, User $user, \Closure $failure)
    {
        // The code check, the credential update and the consumption of the code happen in ONE database transaction (RegistrationResumeService::
        // verifyAndApply). If anything in the update fails, everything rolls back and the code is still valid. The phone-duplicate check is inside it
        // and runs only after the code was proven correct, so it can never be an oracle for an unauthenticated caller.
        try {
            $outcome = $this->resume->verifyAndApply($user, $validated['code'], function (User $locked) use ($validated, $phone) {
                if ($this->phoneService->isDuplicate($phone['phone_e164'], $locked->id)) {
                    throw ValidationException::withMessages([
                        'phone' => ['This phone number is already registered to another account.'],
                    ]);
                }

                $locked->tokens()->delete();   // kill every earlier session, including any obtained by someone else

                $locked->forceFill([
                    'name'                 => $validated['full_name'],
                    'full_name'            => $validated['full_name'],
                    'country_iso2'         => $phone['country_iso2'],
                    'country_code'         => $phone['country_code'],
                    'phone'                => $phone['phone_e164'],
                    'country'              => $phone['country_name'],
                    'password'             => Hash::make($validated['password']),
                    'email_verified_at'    => now(),   // the code was delivered to this mailbox and has just been entered
                    'email_otp'            => null,
                    'email_otp_expires_at' => null,
                    'email_otp_attempts'   => 0,
                ])->save();
                $this->privacyPolicy->record($locked, $validated);

                return $locked->createToken('registration')->plainTextToken;
            });
        } catch (ValidationException $e) {
            $this->discloseOrThrottle($request);   // only reachable after a CORRECT code; nothing was committed and the code is still valid
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('Registration resume could not be completed (rolled back, code not consumed): ' . \App\Services\Registration\RegistrationCommunicationService::safeError($e->getMessage()));
            return response()->json([
                'success' => false,
                'message' => 'We could not complete that just now. Your code has not been used — please try again in a moment.',
            ], 503);
        }

        if ($outcome['locked']) {
            $this->track(fn (RegistrationTracker $t) => $t->resumeLockedOut($user));
        }
        if (!$outcome['ok']) {
            return $failure();
        }
        $token = $outcome['result'];
        $user->refresh();

        $this->track(fn (RegistrationTracker $t) => $t->resumeVerified($user, $validated['visitor_token'] ?? null));

        return response()->json([
            'success'            => true,
            'message'            => 'Verified. Welcome back — let\'s finish your registration.',
            'token'              => $token,
            'email_verified'     => true,
            'registration_stage' => (int) $user->registration_stage,
            'user'               => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    /** New email: the account is created ONLY now, atomically with the consumption of the emailed code. Role/status/balance are server-set (F-12). */
    private function finishNew(Request $request, array $validated, array $phone, \Closure $failure)
    {
        try {
            $outcome = $this->emailChallenges->verifyAndApply($validated['email'], $validated['code'], function () use ($validated, $phone) {
                if ($this->phoneService->isDuplicate($phone['phone_e164'], null)) {
                    throw ValidationException::withMessages([
                        'phone' => ['This phone number is already registered to another account.'],
                    ]);
                }

                $referredBy = null;
                if (!empty($validated['referral_code'])) {
                    $referrer = User::where('referral_code', $validated['referral_code'])->first();
                    if ($referrer) $referredBy = $referrer->id;
                }

                $user = User::unguarded(fn () => User::create([
                    'name'                    => $validated['full_name'],
                    'full_name'               => $validated['full_name'],
                    'email'                   => $validated['email'],
                    'country_iso2'            => $phone['country_iso2'],
                    'country_code'            => $phone['country_code'],
                    'phone'                   => $phone['phone_e164'],
                    'country'                 => $phone['country_name'],
                    'password'                => Hash::make($validated['password']),
                    'role'                    => 'investor',
                    'referral_code'           => app(\App\Services\Task5\ReferralService::class)->newCode(),   // Task 5: collision-checked
                    'referred_by'             => $referredBy,
                    'balance'                 => 0,
                    'registration_stage'      => 1,
                    'registration_completed'  => false,
                    'status'                  => 'active',
                    'email_verified_at'       => now(),   // proven by the emailed code, consumed in this same transaction
                ]));
                $this->privacyPolicy->record($user, $validated);
                return $user;
            });
        } catch (ValidationException $e) {
            $this->discloseOrThrottle($request);   // only reachable after a CORRECT code
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('Registration could not be completed (rolled back, code not consumed): ' . \App\Services\Registration\RegistrationCommunicationService::safeError($e->getMessage()));
            return response()->json([
                'success' => false,
                'message' => 'We could not complete that just now. Your code has not been used — please try again in a moment.',
            ], 503);
        }

        if (!$outcome['ok']) {
            return $failure();
        }

        /** @var User $user */
        $user  = $outcome['result'];
        $token = $user->createToken('registration')->plainTextToken;

        // Everything below is OUTSIDE the transaction and best-effort: the account exists and is verified whatever happens here.
        $this->track(fn (RegistrationTracker $t) => $t->attempt($user, $validated['visitor_token'] ?? null, false));
        if ($user->referred_by) {
            rescue(fn () => app(\App\Services\Task5\ReferralService::class)->log('registration_started', $user->referred_by, $user->id,
                User::whereKey($user->referred_by)->value('referral_code'), ['fp' => app(\App\Services\Task5\ReferralService::class)->fingerprint($request)]), null, true);
        }
        try {
            app(\App\Services\TelegramService::class)->newRegistration($validated['full_name'], $validated['email']);   // only for a PROVEN email
        } catch (\Exception $e) {
            \Log::error('Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'            => true,
            'message'            => 'Email verified. Your account has been created — let\'s continue.',
            'token'              => $token,
            'email_verified'     => true,
            'registration_stage' => 1,
            'user'               => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    // =========================================================================
    // STAGE 2 — KYC / Address Details
    // =========================================================================
    public function submitStage2(Request $request)
    {
        $user = $request->user();

        if (!$user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email before continuing.',
            ], 403);
        }

        $validated = $request->validate([
            'date_of_birth'      => ['required', 'date', 'before:-18 years'],
            'residential_address'=> ['required', 'string', 'max:500'],
            'address_line_2'     => ['nullable', 'string', 'max:255'],
            'city'               => ['required', 'string', 'max:100'],
            'state'              => ['nullable', 'string', 'max:100'],
            'postal_code'        => ['nullable', 'string', 'max:20'],
        ], [
            'date_of_birth.before' => 'You must be at least 18 years old.',
        ]);

        $user->update([
            'date_of_birth'       => $validated['date_of_birth'],
            // NOTE: `residential_address` was previously missing from
            // User::$fillable, so this value was being silently discarded
            // by Eloquent's mass-assignment guard on every registration —
            // it never actually reached the database. Fixed in
            // app/Models/User.php as part of this change.
            'residential_address' => $validated['residential_address'],
            'address_line_2'      => $validated['address_line_2'] ?? null,
            'city'                => $validated['city'],
            'state'               => $validated['state'] ?? null,
            'postal_code'         => $validated['postal_code'] ?? null,
            'registration_stage'  => 2,
        ]);
        $this->track(fn (RegistrationTracker $t) => $t->stageCompleted($user, 2));

        return response()->json(['success' => true, 'message' => 'Stage 2 complete.']);
    }

    // =========================================================================
    // STAGE 3 — Investor Suitability Profile
    // =========================================================================
    public function submitStage3(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'employment_status'    => ['required', 'string', 'max:50'],
            'annual_income_range'  => ['required', 'string', 'max:50'],
            'source_of_funds'      => ['required', 'string', 'max:100'],
            'investment_experience'=> ['required', 'string', 'max:50'],
            'risk_tolerance'       => ['required', 'string', 'max:50'],
            'investment_objectives'=> ['nullable', 'string', 'max:1000'],
        ]);

        $user->update([
            'employment_status'     => $validated['employment_status'],
            'annual_income_range'   => $validated['annual_income_range'],
            'source_of_funds'       => $validated['source_of_funds'],
            'investment_experience' => $validated['investment_experience'],
            'risk_tolerance'        => $validated['risk_tolerance'],
            'investment_objectives' => $validated['investment_objectives'] ?? null,
            'registration_stage'    => 3,
        ]);
        $this->track(fn (RegistrationTracker $t) => $t->stageCompleted($user, 3));

        return response()->json(['success' => true, 'message' => 'Stage 3 complete.']);
    }

    // =========================================================================
    // STAGE 4 — Security Setup / Final Step
    // =========================================================================
    public function submitStage4(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'withdrawal_pin'              => ['nullable', 'digits:4', 'confirmed'],
            'withdrawal_pin_confirmation' => ['nullable'],
            'referral_code'               => ['nullable', 'string', 'max:20'],
        ]);

        if ($request->filled('withdrawal_pin')) {
            $user->withdrawal_pin = Hash::make($request->withdrawal_pin);
        }

        $firstCompletion              = !$user->registration_completed;   // Task 5: log the funnel event once
        $user->registration_stage     = 4;
        $user->registration_completed = true;
        $user->save();
        $this->track(fn (RegistrationTracker $t) => $t->stageCompleted($user, 4));

        // Task 5: registration COMPLETED -> create the Pending reward ledger row. Registration alone never qualifies a referral; eligibility is assessed
        // later against the (Management-approved) policy. Never allowed to break the registration flow.
        if ($user->referred_by) {
            rescue(function () use ($user, $firstCompletion) {
                if ($firstCompletion) app(\App\Services\Task5\ReferralService::class)->log('registration_completed', $user->referred_by, $user->id, User::whereKey($user->referred_by)->value('referral_code'));
                app(\App\Services\Task5\ReferralRewardService::class)->createForReferred($user);
            }, null, true);
        }

        return response()->json([
            'user' => [
                'id'                  => $user->id,
                'name'                => $user->name ?? $user->full_name,
                'email'               => $user->email,
                'role'                => $user->role,
                'balance'             => (float) ($user->balance ?? 0),
                'referral_code'       => $user->referral_code,
                'created_at'          => $user->created_at,
                // Without this, the frontend's setUser() call after this
                // response ends up with registration_stage undefined —
                // isRegistrationComplete() then reads that as "still
                // stage 1" and InvestorRoute bounces straight back to
                // /register right after finishing it.
                'registration_stage'  => $user->registration_stage,
            ],
            'message' => 'Registration complete! Welcome to Smart System Investment.',
        ], 201);
    }

    /**
     * Task 2: registration tracking is strictly best-effort. Any failure is logged and swallowed so it can never block, delay or alter
     * an applicant's registration.
     */
    private function track(callable $fn): void
    {
        try {
            $fn(app(RegistrationTracker::class));
        } catch (\Throwable $e) {
            \Log::warning('Registration tracking failed: ' . $e->getMessage());
        }
    }
}