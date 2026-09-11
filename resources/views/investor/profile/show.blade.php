@extends('layouts.dashboard')

@section('title', 'My Profile')

@section('content')

<div style="background-color: #F9FAFB; min-height: 100vh; padding: 2rem;">
    
    <!-- Header Section -->
    <div style="margin-bottom: 2rem;">
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin-bottom: 0.5rem; font-family: 'Crimson Pro', serif;">
            My Profile
        </h1>
        <p style="font-size: 1.05rem; color: #6B7280;">Manage your account and investment preferences</p>
    </div>

    <!-- Profile Header Card -->
    <div style="background: white; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
        <div style="display: flex; gap: 2rem; align-items: center;">
            
            <!-- Avatar -->
            <div style="flex-shrink: 0;">
                <div style="width: 120px; height: 120px; border-radius: 12px; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem; font-weight: 700; border: 4px solid white; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
            </div>
            
            <!-- Profile Info -->
            <div style="flex: 1;">
                <h2 style="font-size: 2rem; font-weight: 700; color: #1F2937; margin: 0 0 0.5rem 0; font-family: 'Crimson Pro', serif;">
                    {{ auth()->user()->full_name ?? auth()->user()->name }}
                </h2>
                <p style="font-size: 1.05rem; color: #6B7280; margin: 0 0 1rem 0;">
                    {{ auth()->user()->email }}
                </p>
                <div style="display: flex; gap: 1rem;">
                    <a href="{{ route('investor-investment.profile.edit') }}" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%); color: white; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                        ✏️ Edit Profile
                    </a>
                    <div style="display: inline-flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1.5rem; background: #F3F4F6; border-radius: 8px; font-weight: 600; color: #1F2937;">
                        <span style="font-size: 1.2rem;">✅</span>
                        Account Active
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; min-width: 250px;">
                <div style="text-align: center; padding: 1rem; background: #F9FAFB; border-radius: 12px; border: 1px solid #E5E7EB;">
                    <p style="font-size: 0.875rem; color: #6B7280; margin: 0 0 0.5rem 0; font-weight: 500;">Balance</p>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #10B981; margin: 0; font-family: 'Crimson Pro', serif;">
                        ${{ number_format(auth()->user()->balance ?? 0, 2) }}
                    </p>
                </div>
                <div style="text-align: center; padding: 1rem; background: #F9FAFB; border-radius: 12px; border: 1px solid #E5E7EB;">
                    <p style="font-size: 0.875rem; color: #6B7280; margin: 0 0 0.5rem 0; font-weight: 500;">Member Since</p>
                    <p style="font-size: 1.1rem; font-weight: 700; color: #1E3A8A; margin: 0;">
                        {{ auth()->user()->created_at->format('M Y') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem;">
        
        <!-- Left Column - Account Information -->
        <div>
            <!-- Account Details Card -->
            <div style="background: white; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
                <h3 style="font-size: 1.5rem; font-weight: 700; color: #1F2937; margin: 0 0 1.5rem 0; font-family: 'Crimson Pro', serif; display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.5rem;">📋</span>
                    Account Information
                </h3>
                
                <div style="display: grid; gap: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Email Address</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->email }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Full Name</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->full_name ?? 'Not set' }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Phone</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->phone ?? 'Not provided' }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Country</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->country ?? 'Not provided' }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #6B7280; font-weight: 500;">Account Status</span>
                        <span style="padding: 0.5rem 1rem; background: #D1FAE5; color: #065F46; border-radius: 8px; font-weight: 600; font-size: 0.875rem;">
                            ✓ Active
                        </span>
                    </div>
                </div>
            </div>

            <!-- Personal Information Card -->
            <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
                <h3 style="font-size: 1.5rem; font-weight: 700; color: #1F2937; margin: 0 0 1.5rem 0; font-family: 'Crimson Pro', serif; display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.5rem;">👤</span>
                    Personal Details
                </h3>
                
                <div style="display: grid; gap: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Date of Birth</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->date_of_birth?->format('M d, Y') ?? 'Not provided' }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <span style="color: #6B7280; font-weight: 500;">Gender</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->gender ?? 'Not provided' }}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #6B7280; font-weight: 500;">Employment Status</span>
                        <span style="font-weight: 600; color: #1F2937;">{{ auth()->user()->employment_status ?? 'Not provided' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Investment Preferences -->
        <div>
            <!-- Investment Preferences Card -->
            <div style="background: white; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
                <h3 style="font-size: 1.5rem; font-weight: 700; color: #1F2937; margin: 0 0 1.5rem 0; font-family: 'Crimson Pro', serif; display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.5rem;">📊</span>
                    Preferences
                </h3>
                
                <div style="display: grid; gap: 1.5rem;">
                    <div>
                        <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Risk Tolerance</p>
                        <div style="padding: 1rem; background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%); border-radius: 10px; text-align: center;">
                            <p style="font-weight: 600; color: #1F2937; margin: 0;">
                                {{ auth()->user()->risk_tolerance ?? 'Not set' }}
                            </p>
                        </div>
                    </div>
                    
                    <div>
                        <p style="font-size: 0.875rem; color: #6B7280; font-weight: 500; margin: 0 0 0.75rem 0;">Experience Level</p>
                        <div style="padding: 1rem; background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%); border-radius: 10px; text-align: center;">
                            <p style="font-weight: 600; color: #1F2937; margin: 0;">
                                {{ auth()->user()->investment_experience ?? 'Not set' }}
                            </p>
                        </div>
                    </div>
                    
                    <div style="padding: 1rem; background: linear-gradient(135deg, #DBEAFE 0%, #CFFAFE 100%); border-radius: 12px; border-left: 4px solid #2563EB;">
                        <p style="font-weight: 600; color: #1E40AF; margin: 0 0 0.5rem 0; font-size: 0.95rem;">
                            💡 Pro Tip
                        </p>
                        <p style="font-size: 0.875rem; color: #1E40AF; margin: 0; line-height: 1.6;">
                            Keep your profile updated to get personalized investment recommendations
                        </p>
                    </div>
                </div>
            </div>

            <!-- Account Actions -->
            <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
                <h3 style="font-size: 1.25rem; font-weight: 700; color: #1F2937; margin: 0 0 1.5rem 0; display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.3rem;">⚙️</span>
                    Account Actions
                </h3>
                
                <div style="display: grid; gap: 1rem;">
                    <a href="{{ route('investor-investment.profile.edit') }}" style="display: block; padding: 1rem; text-align: center; background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%); color: white; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);">
                        Edit Profile
                    </a>
                    
                    <a href="{{ route('investor-investment.dashboard') }}" style="display: block; padding: 1rem; text-align: center; background: #F3F4F6; color: #1F2937; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s; border: 2px solid #E5E7EB;">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection