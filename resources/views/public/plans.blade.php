@extends('layouts.app')

@section('title', 'Investment Plans - Smart System')

@section('content')
<section class="section">
    <div class="section-header">
        <h2 class="section-title">Investment Plans</h2>
        <p class="section-subtitle">Choose the plan that fits your financial goals</p>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; max-width: 1200px; margin: 0 auto;">
        <!-- Starter Plan -->
        <div style="background: white; border-radius: 20px; padding: 2.5rem; box-shadow: 0 4px 20px var(--shadow); border: 2px solid var(--border);">
            <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;">Starter</h3>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;">Perfect for beginners</p>
            <div style="margin-bottom: 2rem;">
                <span style="font-family: 'Crimson Pro', serif; font-size: 3rem; font-weight: 700; color: var(--primary);">5-7%</span>
                <span style="color: var(--text-secondary); font-size: 1.1rem;">/month</span>
            </div>
            <ul style="list-style: none; margin-bottom: 2rem; line-height: 2;">
                <li>✓ Minimum: $1,000</li>
                <li>✓ Maximum: $4,999</li>
                <li>✓ Daily tasks access</li>
                <li>✓ Withdrawal anytime</li>
                <li>✓ Email support</li>
            </ul>
            <a href="{{ route('register') }}" class="btn btn-primary" style="width: 100%; text-align: center;">Get Started</a>
        </div>

        <!-- Professional Plan -->
        <div style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: 20px; padding: 2.5rem; box-shadow: 0 8px 30px var(--shadow-lg); color: white; transform: scale(1.05); border: 3px solid var(--accent);">
            <div style="background: var(--accent); color: var(--primary-dark); padding: 0.5rem 1rem; border-radius: 20px; display: inline-block; font-weight: 700; font-size: 0.8rem; margin-bottom: 1rem;">MOST POPULAR</div>
            <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem;">Professional</h3>
            <p style="opacity: 0.9; margin-bottom: 2rem;">For serious investors</p>
            <div style="margin-bottom: 2rem;">
                <span style="font-family: 'Crimson Pro', serif; font-size: 3rem; font-weight: 700;">8-12%</span>
                <span style="opacity: 0.9; font-size: 1.1rem;">/month</span>
            </div>
            <ul style="list-style: none; margin-bottom: 2rem; line-height: 2;">
                <li>✓ Minimum: $5,000</li>
                <li>✓ Maximum: $19,999</li>
                <li>✓ Priority tasks</li>
                <li>✓ Fast withdrawals</li>
                <li>✓ Priority support</li>
                <li>✓ Monthly reports</li>
            </ul>
            <a href="{{ route('register') }}" class="btn btn-accent" style="width: 100%; text-align: center;">Get Started</a>
        </div>

        <!-- Elite Plan -->
        <div style="background: white; border-radius: 20px; padding: 2.5rem; box-shadow: 0 4px 20px var(--shadow); border: 2px solid var(--border);">
            <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;">Elite</h3>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;">Maximum returns</p>
            <div style="margin-bottom: 2rem;">
                <span style="font-family: 'Crimson Pro', serif; font-size: 3rem; font-weight: 700; color: var(--primary);">15-20%</span>
                <span style="color: var(--text-secondary); font-size: 1.1rem;">/month</span>
            </div>
            <ul style="list-style: none; margin-bottom: 2rem; line-height: 2;">
                <li>✓ Minimum: $20,000</li>
                <li>✓ No maximum</li>
                <li>✓ Exclusive tasks</li>
                <li>✓ Instant withdrawals</li>
                <li>✓ Dedicated manager</li>
                <li>✓ Weekly reports</li>
                <li>✓ VIP events access</li>
            </ul>
            <a href="{{ route('register') }}" class="btn btn-primary" style="width: 100%; text-align: center;">Get Started</a>
        </div>
    </div>
</section>
@endsection