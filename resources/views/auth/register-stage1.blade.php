@extends('layouts.app')

@section('title', 'Register - Step 1 of 4')

@section('content')
<div class="registration-wrapper">
    <div class="registration-container">
        <!-- Progress Bar -->
        <div class="registration-progress">
            <div class="progress-step active completed">
                <div class="step-circle">1</div>
                <span class="step-label">Basic Info</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <div class="step-circle">2</div>
                <span class="step-label">Verification</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <div class="step-circle">3</div>
                <span class="step-label">Profile</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <div class="step-circle">4</div>
                <span class="step-label">Security</span>
            </div>
        </div>

        <!-- Registration Card -->
        <div class="registration-card">
            <div class="registration-header">
                <h2 class="registration-title">Create Your Account</h2>
                <p class="registration-subtitle">Step 1 of 4: Basic Information</p>
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

            <form method="POST" action="{{ route('register.stage1.submit') }}" class="registration-form">
                @csrf

                <!-- Full Legal Name -->
                <div class="form-group">
                    <label class="form-label">
                        Full Legal Name <span class="required">*</span>
                    </label>
                    <input 
                        type="text"
                        name="full_name"
                        value="{{ old('full_name') }}"
                        class="form-input @error('full_name') error @enderror"
                        placeholder="John Doe"
                        required
                        autofocus
                    />
                    <small class="form-hint">Enter your name exactly as it appears on your government-issued ID</small>
                    @error('full_name')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Email Address -->
                <div class="form-group">
                    <label class="form-label">
                        Email Address <span class="required">*</span>
                    </label>
                    <input 
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="form-input @error('email') error @enderror"
                        placeholder="your@email.com"
                        required
                    />
                    <small class="form-hint">We'll send a verification code to this email</small>
                    @error('email')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Phone Number -->
                <div class="form-row">
                    <div class="form-group" style="flex: 0 0 140px;">
                        <label class="form-label">Code <span class="required">*</span></label>
                        <select name="country_code" class="form-input @error('country_code') error @enderror" required>
                            <option value="+1" {{ old('country_code') == '+1' ? 'selected' : '' }}>🇺🇸 +1</option>
                            <option value="+44" {{ old('country_code') == '+44' ? 'selected' : '' }}>🇬🇧 +44</option>
                            <option value="+234" {{ old('country_code', '+234') == '+234' ? 'selected' : '' }}>🇳🇬 +234</option>
                            <option value="+91" {{ old('country_code') == '+91' ? 'selected' : '' }}>🇮🇳 +91</option>
                            <option value="+86" {{ old('country_code') == '+86' ? 'selected' : '' }}>🇨🇳 +86</option>
                            <option value="+81" {{ old('country_code') == '+81' ? 'selected' : '' }}>🇯🇵 +81</option>
                            <option value="+49" {{ old('country_code') == '+49' ? 'selected' : '' }}>🇩🇪 +49</option>
                            <option value="+33" {{ old('country_code') == '+33' ? 'selected' : '' }}>🇫🇷 +33</option>
                            <option value="+61" {{ old('country_code') == '+61' ? 'selected' : '' }}>🇦🇺 +61</option>
                            <option value="+27" {{ old('country_code') == '+27' ? 'selected' : '' }}>🇿🇦 +27</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Phone Number <span class="required">*</span></label>
                        <input 
                            type="tel"
                            name="phone"
                            value="{{ old('phone') }}"
                            class="form-input @error('phone') error @enderror"
                            placeholder="8012345678"
                            required
                        />
                    </div>
                </div>
                @error('phone')
                    <span class="error-message">{{ $message }}</span>
                @enderror

                <!-- Country of Residence -->
                <div class="form-group">
                    <label class="form-label">
                        Country of Residence <span class="required">*</span>
                    </label>
                    <select name="country" class="form-input @error('country') error @enderror" required>
                        <option value="">Select your country</option>
                        <option value="US" {{ old('country') == 'US' ? 'selected' : '' }}>🇺🇸 United States</option>
                        <option value="GB" {{ old('country') == 'GB' ? 'selected' : '' }}>🇬🇧 United Kingdom</option>
                        <option value="NG" {{ old('country', 'NG') == 'NG' ? 'selected' : '' }}>🇳🇬 Nigeria</option>
                        <option value="IN" {{ old('country') == 'IN' ? 'selected' : '' }}>🇮🇳 India</option>
                        <option value="CN" {{ old('country') == 'CN' ? 'selected' : '' }}>🇨🇳 China</option>
                        <option value="JP" {{ old('country') == 'JP' ? 'selected' : '' }}>🇯🇵 Japan</option>
                        <option value="DE" {{ old('country') == 'DE' ? 'selected' : '' }}>🇩🇪 Germany</option>
                        <option value="FR" {{ old('country') == 'FR' ? 'selected' : '' }}>🇫🇷 France</option>
                        <option value="AU" {{ old('country') == 'AU' ? 'selected' : '' }}>🇦🇺 Australia</option>
                        <option value="ZA" {{ old('country') == 'ZA' ? 'selected' : '' }}>🇿🇦 South Africa</option>
                        <option value="CA" {{ old('country') == 'CA' ? 'selected' : '' }}>🇨🇦 Canada</option>
                        <option value="BR" {{ old('country') == 'BR' ? 'selected' : '' }}>🇧🇷 Brazil</option>
                        <option value="MX" {{ old('country') == 'MX' ? 'selected' : '' }}>🇲🇽 Mexico</option>
                    </select>
                    @error('country')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label">
                        Create Password <span class="required">*</span>
                    </label>
                    <div class="password-wrapper">
                        <input 
                            type="password"
                            name="password"
                            id="password"
                            class="form-input @error('password') error @enderror"
                            placeholder="Minimum 8 characters"
                            required
                        />
                        <button type="button" class="password-toggle" onclick="togglePassword('password')">
                            <svg class="eye-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <path d="M10 7C8.34 7 7 8.34 7 10C7 11.66 8.34 13 10 13C11.66 13 13 11.66 13 10C13 8.34 11.66 7 10 7Z" fill="currentColor"/>
                                <path d="M10 3C5 3 1.73 6.11 1 10C1.73 13.89 5 17 10 17C15 17 18.27 13.89 19 10C18.27 6.11 15 3 10 3Z" fill="currentColor"/>
                            </svg>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-progress" id="strengthBar"></div>
                        </div>
                        <small class="strength-text" id="strengthText">Password strength</small>
                    </div>
                    <small class="form-hint">Must contain uppercase, lowercase, and number</small>
                    @error('password')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label class="form-label">
                        Confirm Password <span class="required">*</span>
                    </label>
                    <div class="password-wrapper">
                        <input 
                            type="password"
                            name="password_confirmation"
                            id="password_confirmation"
                            class="form-input"
                            placeholder="Re-enter your password"
                            required
                        />
                        <button type="button" class="password-toggle" onclick="togglePassword('password_confirmation')">
                            <svg class="eye-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <path d="M10 7C8.34 7 7 8.34 7 10C7 11.66 8.34 13 10 13C11.66 13 13 11.66 13 10C13 8.34 11.66 7 10 7Z" fill="currentColor"/>
                                <path d="M10 3C5 3 1.73 6.11 1 10C1.73 13.89 5 17 10 17C15 17 18.27 13.89 19 10C18.27 6.11 15 3 10 3Z" fill="currentColor"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Referral Code (Optional) -->
                <div class="form-group">
                    <label class="form-label">
                        Referral Code <span class="optional">(Optional)</span>
                    </label>
                    <input 
                        type="text"
                        name="referral_code"
                        value="{{ old('referral_code') }}"
                        class="form-input"
                        placeholder="Enter referral code if you have one"
                    />
                </div>

                <!-- Terms and Risk Disclosure -->
                <div class="form-group">
                    <div class="checkbox-wrapper">
                        <label class="checkbox-label">
                            <input 
                                type="checkbox" 
                                name="terms_accepted" 
                                {{ old('terms_accepted') ? 'checked' : '' }}
                                required
                            >
                            <span class="checkbox-text">
                                I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a> <span class="required">*</span>
                            </span>
                        </label>
                        @error('terms_accepted')
                            <span class="error-message">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="checkbox-wrapper">
                        <label class="checkbox-label">
                            <input 
                                type="checkbox" 
                                name="risk_accepted"
                                {{ old('risk_accepted') ? 'checked' : '' }}
                                required
                            >
                            <span class="checkbox-text">
                                I understand and accept the <a href="#" target="_blank">Investment Risk Disclosure</a> <span class="required">*</span>
                            </span>
                        </label>
                        @error('risk_accepted')
                            <span class="error-message">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-full">
                    Continue to Verification
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M7 14L12 10L7 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </form>

            <div class="form-footer">
                Already have an account? <a href="{{ route('login') }}">Sign in here</a>
            </div>
        </div>
    </div>
</div>

<script>
// Password toggle
function togglePassword(fieldId) {
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

// Password strength checker
document.getElementById('password').addEventListener('input', function(e) {
    const password = e.target.value;
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    const colors = ['#EF4444', '#F59E0B', '#10B981', '#10B981'];
    const texts = ['Weak', 'Fair', 'Good', 'Strong'];
    const widths = ['25%', '50%', '75%', '100%'];
    
    if (password.length === 0) {
        strengthBar.style.width = '0%';
        strengthText.textContent = 'Password strength';
        strengthText.style.color = '#64748B';
    } else {
        strengthBar.style.width = widths[strength - 1];
        strengthBar.style.background = colors[strength - 1];
        strengthText.textContent = texts[strength - 1];
        strengthText.style.color = colors[strength - 1];
    }
});
</script>
@endsection