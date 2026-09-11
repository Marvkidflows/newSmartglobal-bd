@extends('layouts.app')

@section('title', 'FAQ - Smart System')

@section('content')
<section class="section">
    <div class="section-header">
        <h2 class="section-title">Frequently Asked Questions</h2>
        <p class="section-subtitle">Everything you need to know</p>
    </div>
    
    <div style="max-width: 800px; margin: 0 auto;">
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- FAQ Item -->
            <div style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 12px var(--shadow);">
                <h3 style="font-weight: 700; color: var(--primary); margin-bottom: 1rem;">How does Smart System generate returns?</h3>
                <p style="color: var(--text-secondary); line-height: 1.8;">We use advanced algorithmic trading strategies across multiple markets including forex, cryptocurrencies, and stocks. Our AI-powered systems analyze market data 24/7 to identify profitable opportunities.</p>
            </div>

            <div style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 12px var(--shadow);">
                <h3 style="font-weight: 700; color: var(--primary); margin-bottom: 1rem;">What is the minimum investment?</h3>
                <p style="color: var(--text-secondary); line-height: 1.8;">Our Starter plan begins at just $1,000. This makes professional investment management accessible to everyone.</p>
            </div>

            <div style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 12px var(--shadow);">
                <h3 style="font-weight: 700; color: var(--primary); margin-bottom: 1rem;">Can I withdraw my funds anytime?</h3>
                <p style="color: var(--text-secondary); line-height: 1.8;">Yes! We pride ourselves on flexibility. You can request withdrawals at any time, with processing typically completed within 24-48 hours.</p>
            </div>

            <div style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 12px var(--shadow);">
                <h3 style="font-weight: 700; color: var(--primary); margin-bottom: 1rem;">Is my investment secure?</h3>
                <p style="color: var(--text-secondary); line-height: 1.8;">Security is our top priority. We use bank-level encryption, cold storage for crypto assets, and are fully regulated by international financial authorities.</p>
            </div>

            <div style="background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 12px var(--shadow);">
                <h3 style="font-weight: 700; color: var(--primary); margin-bottom: 1rem;">What are daily tasks?</h3>
                <p style="color: var(--text-secondary); line-height: 1.8;">Daily tasks are simple activities like checking market updates or reviewing your portfolio. Completing them earns you bonus rewards on top of your regular investment returns.</p>
            </div>
        </div>
    </div>
</section>
@endsection