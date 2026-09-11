@extends('layouts.app')

@section('title', 'How It Works - Smart System')

@section('content')
<section class="section">
    <div class="section-header">
        <h2 class="section-title">How It Works</h2>
        <p class="section-subtitle">Start investing in 4 simple steps</p>
    </div>
    
    <div style="max-width: 800px; margin: 0 auto;">
        <div style="display: flex; flex-direction: column; gap: 3rem;">
            <!-- Step 1 -->
            <div style="display: flex; gap: 2rem; align-items: start;">
                <div style="flex-shrink: 0; width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700;">1</div>
                <div>
                    <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary);">Create Your Account</h3>
                    <p style="color: var(--text-secondary); line-height: 1.8;">Sign up in minutes with just your email and basic information. No complicated forms or verification delays.</p>
                </div>
            </div>

            <!-- Step 2 -->
            <div style="display: flex; gap: 2rem; align-items: start;">
                <div style="flex-shrink: 0; width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700;">2</div>
                <div>
                    <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary);">Choose Your Plan</h3>
                    <p style="color: var(--text-secondary); line-height: 1.8;">Select from Starter, Professional, or Elite plans based on your investment goals and risk tolerance.</p>
                </div>
            </div>

            <!-- Step 3 -->
            <div style="display: flex; gap: 2rem; align-items: start;">
                <div style="flex-shrink: 0; width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-radius: 50%; display: flex; align-items: center; justify-center; font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700;">3</div>
                <div>
                    <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary);">Make Your Deposit</h3>
                    <p style="color: var(--text-secondary); line-height: 1.8;">Fund your account securely using cryptocurrency, bank transfer, or other supported payment methods.</p>
                </div>
            </div>

            <!-- Step 4 -->
            <div style="display: flex; gap: 2rem; align-items: start;">
                <div style="flex-shrink: 0; width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-radius: 50%; display: flex; align-items: center; justify-center; font-family: 'Crimson Pro', serif; font-size: 1.8rem; font-weight: 700;">4</div>
                <div>
                    <h3 style="font-family: 'Crimson Pro', serif; font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary);">Watch Your Money Grow</h3>
                    <p style="color: var(--text-secondary); line-height: 1.8;">Track your investments in real-time, complete daily tasks for bonuses, and withdraw profits anytime.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection