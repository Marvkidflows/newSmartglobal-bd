@extends('layouts.app')

@section('title', 'Register - Step 4 of 4')

@section('content')
<div class="registration-wrapper">
    <div class="registration-container">
        <!-- Progress Bar -->
        <div class="registration-progress">
            <div class="progress-step completed">
                <div class="step-circle">✓</div>
                <span class="step-label">Basic Info</span>
            </div>
            <div class="progress-line completed"></div>
            <div class="progress-step completed">
                <div class="step-circle">✓</div>
                <span class="step-label">Verification</span>
            </div>
            <div class="progress-line completed"></div>
            <div class="progress-step completed">
                <div class="step-circle">✓</div>
                <span class="step-label">Profile</span>
            </div>
            <div class="progress-line completed"></div>
            <div class="progress-step active">
                <div class="step-circle">4</div>
                <span class="step-label">Security</span>
            </div>
        </div>

        <!-- Registration Card -->
        <div class="registration-card">
            <div class="registration-header">
                <h2 class="registration-title">Security Setup</h2>
                <p class="registration-subtitle">Step 4 of 4: Secure Your Account</p>
                <div class="info-box">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M10 2L3 5V10C3 14.5 6 18 10 19.5C14 18 17 14.5 17 10V5L10 2Z" stroke="#10B981" stroke-width="2"/>
                        <path d="M7.5 10L9.5 12L12.5 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span>Almost done! Set up security features to protect your account and withdrawals.</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="alert alert-error">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
                        <path d="M10 6V11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="10" cy="14" r="1" fill="currentColor"/>
                    </svg>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('register.stage4.submit') }}" class="registration-form">
                @csrf

                <!-- Withdrawal PIN Section -->
                <div class="security-section">
                    <div class="security-section-header">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="11" width="14" height="10" rx="2" stroke="#2563EB" stroke-width="2"/>
                            <path d="M8 11V7C8 4.79 9.79 3 12 3C14.21 3 16 4.79 16 7V11" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="16" r="2" fill="#2563EB"/>
                        </svg>
                        <div>
                            <h3>Withdrawal PIN</h3>
                            <p>Create a 4-digit PIN to authorize withdrawals</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Create 4-Digit PIN <span class="required">*</span>
                        </label>
                        <div class="pin-input-wrapper">
                            <input 
                                type="password"
                                name="withdrawal_pin"
                                id="withdrawal_pin"
                                class="form-input pin-input @error('withdrawal_pin') error @enderror"
                                placeholder="Enter 4-digit PIN"
                                maxlength="4"
                                pattern="[0-9]{4}"
                                required
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                            />
                            <button type="button" class="password-toggle" onclick="togglePin('withdrawal_pin')">
                                <svg class="eye-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path d="M10 7C8.34 7 7 8.34 7 10C7 11.66 8.34 13 10 13C11.66 13 13 11.66 13 10C13 8.34 11.66 7 10 7Z" fill="currentColor"/>
                                    <path d="M10 3C5 3 1.73 6.11 1 10C1.73 13.89 5 17 10 17C15 17 18.27 13.89 19 10C18.27 6.11 15 3 10 3Z" fill="currentColor"/>
                                </svg>
                            </button>
                        </div>
                        <small class="form-hint">Use a PIN you can remember but others cannot guess</small>
                        @error('withdrawal_pin')
                            <span class="error-message">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Confirm 4-Digit PIN <span class="required">*</span>
                        </label>
                        <div class="pin-input-wrapper">
                            <input 
                                type="password"
                                name="withdrawal_pin_confirmation"
                                id="withdrawal_pin_confirmation"
                                class="form-input pin-input"
                                placeholder="Re-enter 4-digit PIN"
                                maxlength="4"
                                pattern="[0-9]{4}"
                                required
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                            />
                            <button type="button" class="password-toggle" onclick="togglePin('withdrawal_pin_confirmation')">
                                <svg class="eye-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path d="M10 7C8.34 7 7 8.34 7 10C7 11.66 8.34 13 10 13C11.66 13 13 11.66 13 10C13 8.34 11.66 7 10 7Z" fill="currentColor"/>
                                    <path d="M10 3C5 3 1.73 6.11 1 10C1.73 13.89 5 17 10 17C15 17 18.27 13.89 19 10C18.27 6.11 15 3 10 3Z" fill="currentColor"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Two-Factor Authentication Section -->
                <div class="security-section">
                    <div class="security-section-header">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2L4 6V12C4 16.5 7.5 20.5 12 22C16.5 20.5 20 16.5 20 12V6L12 2Z" stroke="#10B981" stroke-width="2"/>
                            <path d="M9 12L11 14L15 10" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <div>
                            <h3>Two-Factor Authentication (2FA)</h3>
                            <p>Add an extra layer of security to your account (Optional)</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <label class="checkbox-label-large">
                                <input 
                                    type="checkbox" 
                                    name="enable_2fa"
                                    id="enable_2fa"
                                    value="1"
                                    {{ old('enable_2fa') ? 'checked' : '' }}
                                >
                                <span class="checkbox-text">
                                    <strong>Enable Two-Factor Authentication</strong>
                                    <small>Recommended for enhanced security. You'll receive a code via email when logging in from a new device.</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div id="2fa-notice" class="notice-box" style="display: none;">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M10 6V11" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="10" cy="14" r="1" fill="#10B981"/>
                        </svg>
                        <span>2FA will be activated after registration. You'll need to verify your email to complete the setup.</span>
                    </div>
                </div>

                <!-- Security Tips -->
                <div class="security-tips">
                    <h4>Security Tips</h4>
                    <ul>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                                <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Never share your PIN with anyone, including support staff
                        </li>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                                <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Use a unique PIN different from your password
                        </li>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                                <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Enable 2FA for maximum account protection
                        </li>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                                <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            You can change your PIN anytime from account settings
                        </li>
                    </ul>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-full btn-large">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/>
                        <path d="M7 10L9 12L13 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Complete Registration
                </button>

                <div class="completion-notice">
                    <p>By completing registration, you agree that all information provided is accurate and complete.</p>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePin(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling;
    if (field.type === 'password') {
        field.type = 'text';
        button.classList.add('active');
    } else {
        field.type = 'password';
        button.classList.remove('active');
    }
}

// Show 2FA notice when checkbox is checked
document.getElementById('enable_2fa').addEventListener('change', function() {
    const notice = document.getElementById('2fa-notice');
    if (this.checked) {
        notice.style.display = 'flex';
    } else {
        notice.style.display = 'none';
    }
});

// Check on page load if 2FA was previously checked
if (document.getElementById('enable_2fa').checked) {
    document.getElementById('2fa-notice').style.display = 'flex';
}

// PIN input validation
document.querySelectorAll('.pin-input').forEach(input => {
    input.addEventListener('input', function() {
        // Only allow numbers
        this.value = this.value.replace(/[^0-9]/g, '');
        
        // Limit to 4 digits
        if (this.value.length > 4) {
            this.value = this.value.slice(0, 4);
        }
    });
});
</script>
@endsection