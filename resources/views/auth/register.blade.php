@extends('layouts.app')

@section('title', 'Register - Smart System Investment')

@section('content')
<div class="form-container">
    <div style="text-align: center; margin-bottom: 2rem;">
        <div class="logo" style="font-size: 2.5rem; margin-bottom: 1rem;">Smart<span>System</span></div>
    </div>
    
    <h2 class="form-title">Create Your Account</h2>
    <p style="text-align: center; color: var(--text-secondary); margin-bottom: 2rem;">Start your investment journey today</p>
    
    @if ($errors->any())
        <div class="alert alert-error">
            <ul style="margin: 0; padding-left: 1.5rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        
        <div class="form-group">
            <label class="form-label">Full Name</label>
            <input 
                type="text"
                name="name"
                value="{{ old('name') }}"
                class="form-input"
                placeholder="John Doe"
                required
                autofocus
            />
        </div>

        <div class="form-group">
            <label class="form-label">Email Address</label>
            <input 
                type="email"
                name="email"
                value="{{ old('email') }}"
                class="form-input"
                placeholder="your@email.com"
                required
            />
        </div>
        
        <div class="form-group">
            <label class="form-label">Password</label>
            <input 
                type="password"
                name="password"
                class="form-input"
                placeholder="Minimum 8 characters"
                required
            />
        </div>

        <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input 
                type="password"
                name="password_confirmation"
                class="form-input"
                placeholder="Re-enter your password"
                required
            />
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: start; cursor: pointer;">
                <input type="checkbox" name="terms" required style="margin-right: 0.75rem; margin-top: 0.25rem;">
                <span style="font-size: 0.9rem; line-height: 1.6;">
                    I agree to the <a href="#" style="color: var(--primary); text-decoration: underline;">Terms of Service</a> 
                    and <a href="#" style="color: var(--primary); text-decoration: underline;">Privacy Policy</a>
                </span>
            </label>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1rem;">
            Create Account
        </button>
    </form>

    <div class="form-link">
        Already have an account? <a href="{{ route('login') }}">Sign in here</a>
    </div>
</div>
@endsection