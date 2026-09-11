@extends('layouts.app')

@section('title', 'Login - Smart System Investment')

@section('content')
<div class="form-container">
    <div style="text-align: center; margin-bottom: 2rem;">
        <div class="logo" style="font-size: 2.5rem; margin-bottom: 1rem;">Smart<span>System</span></div>
    </div>
    
    <h2 class="form-title">Welcome Back</h2>
    
    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first() }}
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        
        <div class="form-group">
            <label class="form-label">Email Address</label>
            <input 
                type="email"
                name="email"
                value="{{ old('email') }}"
                class="form-input"
                placeholder="your@email.com"
                required
                autofocus
            />
        </div>
        
        <div class="form-group">
            <label class="form-label">Password</label>
            <input 
                type="password"
                name="password"
                class="form-input"
                placeholder="Enter your password"
                required
            />
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" name="remember" style="margin-right: 0.5rem;">
                <span style="font-size: 0.9rem;">Remember me</span>
            </label>
            
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" style="color: var(--primary); text-decoration: none; font-size: 0.9rem; font-weight: 600;">
                    Forgot Password?
                </a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1rem;">
            Sign In to Account
        </button>
    </form>

    <div class="form-link">
        Don't have an account? <a href="{{ route('register') }}">Register here</a>
    </div>

    
</div>
@endsection