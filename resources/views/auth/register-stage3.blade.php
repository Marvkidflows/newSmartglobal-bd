@extends('layouts.app')

@section('title', 'Register - Step 3 of 4')

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
            <div class="progress-step active">
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
                <h2 class="registration-title">Investor Profile</h2>
                <p class="registration-subtitle">Step 3 of 4: Investor Suitability Assessment</p>
                <div class="info-box">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="#2563EB" stroke-width="2"/>
                        <path d="M10 6V11" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="10" cy="14" r="1" fill="#2563EB"/>
                    </svg>
                    <span>This information helps us understand your investment experience and recommend suitable investment options.</span>
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

            <form method="POST" action="{{ route('register.stage3.submit') }}" class="registration-form">
                @csrf

                <!-- Employment Status -->
                <div class="form-group">
                    <label class="form-label">
                        Employment Status <span class="required">*</span>
                    </label>
                    <select name="employment_status" class="form-input @error('employment_status') error @enderror" required>
                        <option value="">Select your employment status</option>
                        <option value="employed" {{ old('employment_status') == 'employed' ? 'selected' : '' }}>Employed (Full-time/Part-time)</option>
                        <option value="self_employed" {{ old('employment_status') == 'self_employed' ? 'selected' : '' }}>Self-Employed / Business Owner</option>
                        <option value="unemployed" {{ old('employment_status') == 'unemployed' ? 'selected' : '' }}>Unemployed</option>
                        <option value="retired" {{ old('employment_status') == 'retired' ? 'selected' : '' }}>Retired</option>
                        <option value="student" {{ old('employment_status') == 'student' ? 'selected' : '' }}>Student</option>
                    </select>
                    @error('employment_status')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Annual Income Range -->
                <div class="form-group">
                    <label class="form-label">
                        Annual Income Range <span class="required">*</span>
                    </label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="annual_income_range" value="below_25k" {{ old('annual_income_range') == 'below_25k' ? 'checked' : '' }} required>
                            <span class="radio-text">
                                <strong>Below $25,000</strong>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="annual_income_range" value="25k_50k" {{ old('annual_income_range') == '25k_50k' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>$25,000 - $50,000</strong>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="annual_income_range" value="50k_100k" {{ old('annual_income_range', '50k_100k') == '50k_100k' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>$50,000 - $100,000</strong>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="annual_income_range" value="100k_250k" {{ old('annual_income_range') == '100k_250k' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>$100,000 - $250,000</strong>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="annual_income_range" value="above_250k" {{ old('annual_income_range') == 'above_250k' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Above $250,000</strong>
                            </span>
                        </label>
                    </div>
                    @error('annual_income_range')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Source of Funds -->
                <div class="form-group">
                    <label class="form-label">
                        Source of Funds <span class="required">*</span>
                    </label>
                    <select name="source_of_funds" class="form-input @error('source_of_funds') error @enderror" required>
                        <option value="">Select your primary source of funds</option>
                        <option value="salary" {{ old('source_of_funds') == 'salary' ? 'selected' : '' }}>Salary / Employment Income</option>
                        <option value="business_income" {{ old('source_of_funds') == 'business_income' ? 'selected' : '' }}>Business Income</option>
                        <option value="investments" {{ old('source_of_funds') == 'investments' ? 'selected' : '' }}>Investment Returns</option>
                        <option value="inheritance" {{ old('source_of_funds') == 'inheritance' ? 'selected' : '' }}>Inheritance / Gift</option>
                        <option value="savings" {{ old('source_of_funds') == 'savings' ? 'selected' : '' }}>Savings</option>
                        <option value="other" {{ old('source_of_funds') == 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                    @error('source_of_funds')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Investment Experience -->
                <div class="form-group">
                    <label class="form-label">
                        Investment Experience <span class="required">*</span>
                    </label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="investment_experience" value="none" {{ old('investment_experience') == 'none' ? 'checked' : '' }} required>
                            <span class="radio-text">
                                <strong>None</strong>
                                <small>I have no prior investment experience</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="investment_experience" value="beginner" {{ old('investment_experience', 'beginner') == 'beginner' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Beginner</strong>
                                <small>Less than 2 years of investment experience</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="investment_experience" value="intermediate" {{ old('investment_experience') == 'intermediate' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Intermediate</strong>
                                <small>2-5 years of investment experience</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="investment_experience" value="experienced" {{ old('investment_experience') == 'experienced' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Experienced</strong>
                                <small>5-10 years of investment experience</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="investment_experience" value="expert" {{ old('investment_experience') == 'expert' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Expert</strong>
                                <small>Over 10 years of investment experience</small>
                            </span>
                        </label>
                    </div>
                    @error('investment_experience')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Risk Tolerance -->
                <div class="form-group">
                    <label class="form-label">
                        Risk Tolerance <span class="required">*</span>
                    </label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="risk_tolerance" value="conservative" {{ old('risk_tolerance') == 'conservative' ? 'checked' : '' }} required>
                            <span class="radio-text">
                                <strong>Conservative (Low Risk)</strong>
                                <small>I prefer stable, lower returns with minimal risk</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="risk_tolerance" value="moderate" {{ old('risk_tolerance', 'moderate') == 'moderate' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Moderate (Medium Risk)</strong>
                                <small>I accept some risk for potentially higher returns</small>
                            </span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="risk_tolerance" value="aggressive" {{ old('risk_tolerance') == 'aggressive' ? 'checked' : '' }}>
                            <span class="radio-text">
                                <strong>Aggressive (High Risk)</strong>
                                <small>I seek maximum returns and can handle significant volatility</small>
                            </span>
                        </label>
                    </div>
                    @error('risk_tolerance')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Investment Objectives -->
                <div class="form-group">
                    <label class="form-label">
                        Investment Objectives <span class="required">*</span>
                    </label>
                    <textarea 
                        name="investment_objectives"
                        class="form-input @error('investment_objectives') error @enderror"
                        placeholder="Please describe your investment goals (e.g., retirement planning, wealth accumulation, passive income, etc.)"
                        rows="4"
                        required
                    >{{ old('investment_objectives') }}</textarea>
                    <small class="form-hint">Minimum 50 characters</small>
                    @error('investment_objectives')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-full">
                    Continue to Security Setup
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M7 14L12 10L7 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>
@endsection