<?php
// LOCATION: app/Services/PrivacyPolicyService.php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Single place that knows the current Data Privacy Policy version, whether
 * acknowledgement is mandatory, how to validate it, and how to record it.
 */
class PrivacyPolicyService
{
    public function version(): string
    {
        return (string) config('privacy.version');
    }

    public function acknowledgementRequired(): bool
    {
        return (bool) config('privacy.acknowledgement_required');
    }

    /** Public, non-sensitive metadata for the policy page / registration form. */
    public function meta(): array
    {
        return [
            'version'                  => $this->version(),
            'effective_date'           => config('privacy.effective_date'),
            'approved'                 => (bool) config('privacy.approved'),
            'acknowledgement_required' => $this->acknowledgementRequired(),
        ];
    }

    /**
     * Validation rules for the registration request.
     *
     * - Required mode: the box must be ticked ("accepted") AND the version
     *   must match the current one, so a stale page cannot acknowledge an
     *   old version.
     * - Optional mode: nothing is demanded, but if the client does claim an
     *   acknowledgement it must still name the current version.
     */
    public function rules(): array
    {
        $required = $this->acknowledgementRequired();

        return [
            'privacy_policy_acknowledged' => $required
                ? ['required', 'accepted']
                : ['sometimes', 'boolean'],
            'privacy_policy_version' => [
                Rule::requiredIf(fn () => $required || request()->boolean('privacy_policy_acknowledged')),
                'nullable',
                'string',
                'max:64',
                Rule::in([$this->version()]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'privacy_policy_acknowledged.required' => 'You must acknowledge the Data Privacy Policy to create an account.',
            'privacy_policy_acknowledged.accepted' => 'You must acknowledge the Data Privacy Policy to create an account.',
            'privacy_policy_version.required'      => 'Please reload the page and review the current Data Privacy Policy.',
            'privacy_policy_version.in'            => 'The Data Privacy Policy has been updated. Please reload the page and review the current version.',
        ];
    }

    /**
     * Record the acknowledgement on the user — only when the validated request
     * actually acknowledged it. forceFill because these columns are
     * deliberately NOT mass-assignable ($fillable), so no other endpoint can
     * write them from a request payload.
     */
    public function record(User $user, array $validated): void
    {
        if (empty($validated['privacy_policy_acknowledged'])) {
            return;
        }

        $user->forceFill([
            'privacy_policy_version'         => $this->version(),
            'privacy_policy_acknowledged_at' => now(),
        ])->save();
    }
}
