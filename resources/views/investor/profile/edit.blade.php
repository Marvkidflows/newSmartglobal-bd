@extends('layouts.dashboard')

@section('title', 'Edit Profile')

@section('content')

<div style="background-color: #F9FAFB; min-height: 100vh; padding: 2rem;">
    
    <!-- Header -->
    <div style="margin-bottom: 2rem;">
        <a href="{{ route('investor-investment.profile.show') }}" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #2563EB; text-decoration: none; font-weight: 600; margin-bottom: 1rem;">
            ← Back to Profile
        </a>
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin: 0; font-family: 'Crimson Pro', serif;">
            Edit Profile
        </h1>
        <p style="color: #6B7280; margin-top: 0.5rem;">Update your account information</p>
    </div>

    <!-- Form Card -->
    <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; max-width: 900px;">
        
        <form action="{{ route('investor-investment.profile.update') }}" method="POST">
            @csrf
            @method('PUT')
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                
                <!-- Full Name -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Full Name</label>
                    <input type="text" name="full_name" value="{{ auth()->user()->full_name }}" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem;">
                </div>

                <!-- Email (Read Only) -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Email</label>
                    <input type="email" value="{{ auth()->user()->email }}" readonly style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: #F9FAFB; color: #6B7280;">
                </div>

                <!-- Phone -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Phone</label>
                    <input type="tel" name="phone" value="{{ auth()->user()->phone }}" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem;">
                </div>

                <!-- Country -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Country</label>
                    <input type="text" name="country" value="{{ auth()->user()->country }}" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem;">
                </div>

                <!-- Date of Birth -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Date of Birth</label>
                    <input type="date" name="date_of_birth" value="{{ auth()->user()->date_of_birth?->format('Y-m-d') }}" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem;">
                </div>

                <!-- Gender -->
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Gender</label>
                    <select name="gender" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                        <option value="">Select Gender</option>
                        <option value="male" {{ auth()->user()->gender === 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ auth()->user()->gender === 'female' ? 'selected' : '' }}>Female</option>
                        <option value="other" {{ auth()->user()->gender === 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>
            </div>

            <!-- Employment Status -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Employment Status</label>
                <select name="employment_status" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                    <option value="">Select Employment Status</option>
                    <option value="employed" {{ auth()->user()->employment_status === 'employed' ? 'selected' : '' }}>Employed</option>
                    <option value="self-employed" {{ auth()->user()->employment_status === 'self-employed' ? 'selected' : '' }}>Self-Employed</option>
                    <option value="retired" {{ auth()->user()->employment_status === 'retired' ? 'selected' : '' }}>Retired</option>
                    <option value="student" {{ auth()->user()->employment_status === 'student' ? 'selected' : '' }}>Student</option>
                    <option value="unemployed" {{ auth()->user()->employment_status === 'unemployed' ? 'selected' : '' }}>Unemployed</option>
                </select>
            </div>

            <!-- Investment Preferences -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem; padding: 1.5rem; background: #F9FAFB; border-radius: 12px; border: 1px solid #E5E7EB;">
                
                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Risk Tolerance</label>
                    <select name="risk_tolerance" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                        <option value="">Select Risk Level</option>
                        <option value="low" {{ auth()->user()->risk_tolerance === 'low' ? 'selected' : '' }}>Low Risk</option>
                        <option value="medium" {{ auth()->user()->risk_tolerance === 'medium' ? 'selected' : '' }}>Medium Risk</option>
                        <option value="high" {{ auth()->user()->risk_tolerance === 'high' ? 'selected' : '' }}>High Risk</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">Experience Level</label>
                    <select name="investment_experience" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                        <option value="">Select Experience</option>
                        <option value="beginner" {{ auth()->user()->investment_experience === 'beginner' ? 'selected' : '' }}>Beginner</option>
                        <option value="intermediate" {{ auth()->user()->investment_experience === 'intermediate' ? 'selected' : '' }}>Intermediate</option>
                        <option value="advanced" {{ auth()->user()->investment_experience === 'advanced' ? 'selected' : '' }}>Advanced</option>
                    </select>
                </div>
            </div>

            <!-- Info Box -->
            <div style="padding: 1.5rem; background: linear-gradient(135deg, #DBEAFE 0%, #CFFAFE 100%); border-radius: 12px; border-left: 4px solid #2563EB; margin-bottom: 2rem;">
                <p style="color: #1E40AF; margin: 0; font-weight: 600;">
                    ℹ️ Note
                </p>
                <p style="color: #1E40AF; font-size: 0.95rem; margin: 0.5rem 0 0 0; line-height: 1.6;">
                    Email address cannot be changed. Contact support if you need to update your email.
                </p>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <a href="{{ route('investor-investment.profile.show') }}" style="padding: 0.875rem 2rem; background: #F3F4F6; color: #1F2937; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s; border: 2px solid #E5E7EB;">
                    Cancel
                </a>
                <button type="submit" style="padding: 0.875rem 2rem; background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                    ✅ Save Changes
                </button>
            </div>
        </form>
    </div>

</div>

@endsection