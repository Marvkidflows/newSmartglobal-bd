@extends('layouts.app')

@section('title', 'Smart System Investment - Professional Investment Platform')

@section('content')

<!-- Hero Section with Background Image -->
<section class="hero-modern">
    <div class="hero-overlay"></div>
    <div class="container hero-content">
        <div class="hero-grid">
            <div class="hero-left">
                <div class="hero-badge">
                    <span class="pulse-dot"></span>
                    Trusted by 10,000+ Investors Worldwide
                </div>
                <h1 class="hero-title">
                    Realize Your Potential With the 
                    <span class="highlight-blue">Right Investment</span>
                </h1>
                <p class="hero-subtitle">
                    Discover a wide range of high-quality investment opportunities designed to support your financial growth and well-being. From starter plans to elite strategies, we provide the perfect solution for every investor.
                </p>
                <div class="hero-actions">
                    <a href="{{ route('register') }}" class="btn-hero-primary">
                        Get Started
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7 13L10 10L7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </a>
                    <div class="hero-reviews">
                        <div class="review-avatars">
                            <img src="https://ui-avatars.com/api/?name=John+Doe&background=2563EB&color=fff" alt="Investor 1">
                            <img src="https://ui-avatars.com/api/?name=Sarah+Smith&background=1E3A8A&color=fff" alt="Investor 2">
                            <img src="https://ui-avatars.com/api/?name=Mike+Johnson&background=3B82F6&color=fff" alt="Investor 3">
                        </div>
                        <div class="review-text">
                            <div class="stars">⭐⭐⭐⭐⭐</div>
                            <span>(1.5k+ Reviews)</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="hero-right">
                <!-- Investment Dashboard Cards -->
                <div class="investment-cards">
                    <!-- Daily Returns Card -->
                    <div class="floating-investment-card card-1">
                        <h4 class="investment-card-title">Daily Returns</h4>
                        <div class="investment-card-amount">+$127.50</div>
                        <div class="investment-card-change">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M8 12V4M8 4L4 8M8 4L12 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            2.5% FROM YESTERDAY
                        </div>
                    </div>

                    <!-- Total Earnings Card -->
                    <div class="floating-investment-card card-2">
                        <h4 class="investment-card-title">Total Earnings</h4>
                        <div class="investment-card-amount gold">$5,420</div>
                        <div class="investment-card-subtitle">SINCE JAN 2025</div>
                    </div>

                    <!-- Portfolio Value Card -->
                    <div class="floating-investment-card card-3">
                        <h4 class="investment-card-title">Portfolio Value</h4>
                        <div class="investment-card-amount">20</div>
                        <div class="investment-card-subtitle">IN ACTIVE</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section with Real-Time Counter -->
<section class="stats-section">
    <div class="container">
        <div class="stats-grid-modern">
            <div class="stat-card">
                <h3 class="stat-number counter" data-target="25000">0</h3>
                <p class="stat-label">Years Experience</p>
                <div class="stat-live-badge">
                    <span class="live-dot"></span>
                    LIVE
                </div>
            </div>
            <div class="stat-card">
                <h3 class="stat-number counter" data-target="98">0</h3>
                <p class="stat-label">Client Satisfaction</p>
                <div class="stat-live-badge">
                    <span class="live-dot"></span>
                    LIVE
                </div>
            </div>
            <div class="stat-card">
                <h3 class="stat-number counter" data-target="150">0</h3>
                <p class="stat-label">Expert Team</p>
                <div class="stat-live-badge">
                    <span class="live-dot"></span>
                    LIVE
                </div>
            </div>
            <div class="stat-card">
                <h3 class="stat-number counter" data-target="15">0</h3>
                <p class="stat-label">Annual ROI</p>
                <div class="stat-live-badge">
                    <span class="live-dot"></span>
                    LIVE
                </div>
            </div>
        </div>
        <div class="collaborate-section">
            <h4 class="collaborate-text">We Collaborate With <span class="highlight-number">1500+</span> Companies</h4>
            <div class="company-logos">
                <div class="logo-item">LOGOIPSUM</div>
                <div class="logo-item">logoipsum.com</div>
                <div class="logo-item">logoipsum</div>
            </div>
        </div>
    </div>
</section>

<script>
// Real-time counter animation
document.addEventListener('DOMContentLoaded', function() {
    const counters = document.querySelectorAll('.counter');
    
    const animateCounter = (counter) => {
        const target = parseInt(counter.getAttribute('data-target'));
        const duration = 2000; // 2 seconds
        const step = target / (duration / 16); // 60fps
        let current = 0;
        
        const updateCounter = () => {
            current += step;
            if (current < target) {
                counter.textContent = Math.floor(current).toLocaleString();
                requestAnimationFrame(updateCounter);
            } else {
                counter.textContent = target.toLocaleString();
            }
        };
        
        updateCounter();
    };
    
    // Intersection Observer to trigger animation when visible
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    counters.forEach(counter => observer.observe(counter));
});
</script>

<!-- About Section -->
<section class="about-section">
    <div class="container">
        <div class="about-grid">
            <div class="about-left">
                <div class="section-badge">About Smart System</div>
                <h2 class="section-title">Leading the Way in Investment Excellence</h2>
                <p class="section-description">
                    Since 2018, Smart System Investment has been at the forefront of innovative investment solutions. We combine cutting-edge technology with proven strategies to deliver exceptional returns for our clients.
                </p>
                <div class="about-features">
                    <div class="feature-item">
                        <div class="feature-icon blue">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2L4 6V12C4 16.5 7.5 20.5 12 22C16.5 20.5 20 16.5 20 12V6L12 2Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="feature-content">
                            <h4>SEC Regulated & Licensed</h4>
                            <p>Fully compliant with international financial regulations and standards</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon green">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <path d="M4 20L12 12L16 16L22 8M22 8V14M22 8H16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="feature-content">
                            <h4>Proven Track Record</h4>
                            <p>Consistent returns and satisfied investors across 256+ countries</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon purple">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
                                <path d="M4 20C4 16 7.6 13 12 13C16.4 13 20 16 20 20" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="feature-content">
                            <h4>Expert Management Team</h4>
                            <p>150+ investment professionals with decades of combined experience</p>
                        </div>
                    </div>
                </div>
                <a href="{{ route('about') }}" class="btn-outline-primary">
                    Learn More About Us
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M6 12L10 8L6 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </a>
            </div>
            <div class="about-right">
                <div class="about-image">
                    <img src="https://images.unsplash.com/photo-1559526324-593bc073d938?w=600&h=800&fit=crop" alt="Professional Team" class="main-image">
                    <div class="floating-card">
                        <div class="floating-icon">📊</div>
                        <div class="floating-info">
                            <h5>$2.8M+</h5>
                            <p>Assets Under Management</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Services Section -->
<section class="services-section">
    <div class="container">
        <div class="section-header-center">
            <div class="section-badge">Our Services</div>
            <h2 class="section-title">Comprehensive Investment Solutions</h2>
            <p class="section-description">
                We offer a complete suite of investment services designed to help you achieve your financial goals
            </p>
        </div>
        <div class="services-grid">
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <rect x="8" y="12" width="24" height="20" rx="2" stroke="#2563EB" stroke-width="2"/>
                        <path d="M14 12V10C14 7.8 15.8 6 18 6H22C24.2 6 26 7.8 26 10V12" stroke="#2563EB" stroke-width="2"/>
                        <circle cx="20" cy="22" r="2" fill="#2563EB"/>
                    </svg>
                </div>
                <h3 class="service-title">Portfolio Management</h3>
                <p class="service-description">
                    Professional portfolio management tailored to your risk tolerance and financial objectives
                </p>
                <a href="{{ route('plans') }}" class="service-link">Learn More →</a>
            </div>
            
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <path d="M8 30L16 22L22 26L32 14M32 14V20M32 14H26" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <h3 class="service-title">AI-Powered Trading</h3>
                <p class="service-description">
                    Advanced algorithms that analyze markets 24/7 to maximize your investment returns
                </p>
                <a href="{{ route('how-it-works') }}" class="service-link">Learn More →</a>
            </div>
            
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <circle cx="20" cy="20" r="14" stroke="#2563EB" stroke-width="2"/>
                        <path d="M20 12V20L26 23" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <h3 class="service-title">Daily Task Rewards</h3>
                <p class="service-description">
                    Complete simple daily tasks and earn additional bonus rewards on top of your investment returns
                </p>
                <a href="{{ route('register') }}" class="service-link">Learn More →</a>
            </div>
            
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <path d="M20 6L12 10V16C12 21 15 25 20 28C25 25 28 21 28 16V10L20 6Z" stroke="#2563EB" stroke-width="2"/>
                    </svg>
                </div>
                <h3 class="service-title">Secure Withdrawals</h3>
                <p class="service-description">
                    Fast and secure withdrawal processing with multiple payment methods available
                </p>
                <a href="{{ route('register') }}" class="service-link">Learn More →</a>
            </div>
            
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <circle cx="20" cy="14" r="5" stroke="#2563EB" stroke-width="2"/>
                        <path d="M10 30C10 25 14.5 22 20 22C25.5 22 30 25 30 30" stroke="#2563EB" stroke-width="2"/>
                    </svg>
                </div>
                <h3 class="service-title">Expert Consultation</h3>
                <p class="service-description">
                    One-on-one consultations with our investment experts to optimize your strategy
                </p>
                <a href="{{ route('contact') }}" class="service-link">Learn More →</a>
            </div>
            
            <div class="service-card">
                <div class="service-icon">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <rect x="10" y="10" width="20" height="20" rx="2" stroke="#2563EB" stroke-width="2"/>
                        <path d="M10 16H30M16 10V30" stroke="#2563EB" stroke-width="2"/>
                    </svg>
                </div>
                <h3 class="service-title">Real-Time Analytics</h3>
                <p class="service-description">
                    Track your investments in real-time with comprehensive analytics and reporting tools
                </p>
                <a href="{{ route('register') }}" class="service-link">Learn More →</a>
            </div>
        </div>
    </div>
</section>

<!-- Investment Plans Section -->
<section class="plans-section-modern">
    <div class="container">
        <div class="section-header-center">
            <div class="section-badge">Investment Plans</div>
            <h2 class="section-title">Choose Your Perfect Plan</h2>
            <p class="section-description">
                Flexible investment tiers designed to match your financial goals and risk tolerance
            </p>
        </div>
        
        <div class="plans-grid-modern">
            <!-- Starter Plan -->
            <div class="plan-card-modern">
                <div class="plan-header-modern">
                    <h3 class="plan-name-modern">Starter</h3>
                    <p class="plan-subtitle-modern">Perfect for beginners</p>
                </div>
                <div class="plan-price-modern">
                    <span class="currency">$</span>
                    <span class="amount">1,000</span>
                    <span class="period">minimum</span>
                </div>
                <div class="plan-returns-modern">
                    <div class="returns-badge">5% - 7% Monthly</div>
                </div>
                <ul class="plan-features-modern">
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Daily profit distribution
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Basic daily tasks
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Email support
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Withdraw anytime
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Mobile app access
                    </li>
                </ul>
                <a href="{{ route('register') }}" class="plan-btn-modern">Get Started</a>
            </div>
            
            <!-- Professional Plan -->
            <div class="plan-card-modern featured">
                <div class="popular-ribbon">Most Popular</div>
                <div class="plan-header-modern">
                    <h3 class="plan-name-modern">Professional</h3>
                    <p class="plan-subtitle-modern">For serious investors</p>
                </div>
                <div class="plan-price-modern">
                    <span class="currency">$</span>
                    <span class="amount">5,000</span>
                    <span class="period">minimum</span>
                </div>
                <div class="plan-returns-modern">
                    <div class="returns-badge featured">8% - 12% Monthly</div>
                </div>
                <ul class="plan-features-modern">
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Everything in Starter
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Priority withdrawals
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Advanced tasks (higher rewards)
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Priority support
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Monthly performance reports
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Investment consultation
                    </li>
                </ul>
                <a href="{{ route('register') }}" class="plan-btn-modern featured">Get Started</a>
            </div>
            
            <!-- Elite Plan -->
            <div class="plan-card-modern">
                <div class="plan-header-modern">
                    <h3 class="plan-name-modern">Elite</h3>
                    <p class="plan-subtitle-modern">Maximum returns</p>
                </div>
                <div class="plan-price-modern">
                    <span class="currency">$</span>
                    <span class="amount">20,000</span>
                    <span class="period">minimum</span>
                </div>
                <div class="plan-returns-modern">
                    <div class="returns-badge">15% - 20% Monthly</div>
                </div>
                <ul class="plan-features-modern">
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Everything in Professional
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Instant withdrawals
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        VIP exclusive tasks
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Dedicated account manager
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Weekly strategy calls
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        VIP events & webinars
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="9" stroke="#10B981" stroke-width="2"/>
                            <path d="M7 10L9 12L13 8" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Custom investment strategies
                    </li>
                </ul>
                <a href="{{ route('register') }}" class="plan-btn-modern">Get Started</a>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="how-it-works-section">
    <div class="container">
        <div class="section-header-center">
            <div class="section-badge">Simple Process</div>
            <h2 class="section-title">How It Works</h2>
            <p class="section-description">
                Get started with Smart System Investment in 4 simple steps
            </p>
        </div>
        
        <div class="steps-timeline">
            <div class="step-item-timeline">
                <div class="step-number-badge">1</div>
                <div class="step-content-box">
                    <div class="step-icon-box">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <circle cx="16" cy="16" r="14" stroke="#2563EB" stroke-width="2"/>
                            <path d="M16 10V16L20 18" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="step-title-box">Create Your Account</h3>
                    <p class="step-description-box">Sign up in less than 2 minutes with just your email address. No complex verification required to get started.</p>
                </div>
            </div>
            
            <div class="step-connector-line"></div>
            
            <div class="step-item-timeline">
                <div class="step-number-badge">2</div>
                <div class="step-content-box">
                    <div class="step-icon-box">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <rect x="8" y="12" width="16" height="14" rx="2" stroke="#2563EB" stroke-width="2"/>
                            <path d="M12 12V10C12 8.4 13.4 7 15 7H17C18.6 7 20 8.4 20 10V12" stroke="#2563EB" stroke-width="2"/>
                        </svg>
                    </div>
                    <h3 class="step-title-box">Choose Your Plan</h3>
                    <p class="step-description-box">Select from Starter, Professional, or Elite plans based on your investment goals and risk tolerance.</p>
                </div>
            </div>
            
            <div class="step-connector-line"></div>
            
            <div class="step-item-timeline">
                <div class="step-number-badge">3</div>
                <div class="step-content-box">
                    <div class="step-icon-box">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <circle cx="16" cy="16" r="10" stroke="#2563EB" stroke-width="2"/>
                            <path d="M12 16L14 18L20 12" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="step-title-box">Make Your Deposit</h3>
                    <p class="step-description-box">Fund your account securely using cryptocurrency, bank transfer, or other supported payment methods.</p>
                </div>
            </div>
            
            <div class="step-connector-line"></div>
            
            <div class="step-item-timeline">
                <div class="step-number-badge">4</div>
                <div class="step-content-box">
                    <div class="step-icon-box">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <path d="M6 24L14 16L20 20L26 12" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                            <path d="M20 12H26V18" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="step-title-box">Watch Your Money Grow</h3>
                    <p class="step-description-box">Track your investments in real-time, complete daily tasks for bonuses, and withdraw profits anytime you want.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonials Section -->
<section class="testimonials-section-modern">
    <div class="container">
        <div class="section-header-center">
            <div class="section-badge">Success Stories</div>
            <h2 class="section-title">What Our Investors Say</h2>
            <p class="section-description">
                Join thousands of satisfied investors who trust Smart System with their financial future
            </p>
        </div>
        
        <div class="testimonials-grid-modern">
            <div class="testimonial-card-modern">
                <div class="testimonial-stars">⭐⭐⭐⭐⭐</div>
                <p class="testimonial-quote">
                    "I started with the Starter plan and within 6 months, I've grown my portfolio significantly. The platform is incredibly user-friendly and the support team is outstanding!"
                </p>
                <div class="testimonial-author">
                    <img src="https://ui-avatars.com/api/?name=John+Davidson&background=2563EB&color=fff&size=48" alt="John Davidson" class="author-photo">
                    <div class="author-info">
                        <h4 class="author-name">John Davidson</h4>
                        <p class="author-role">Professional Tier Investor</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-card-modern">
                <div class="testimonial-stars">⭐⭐⭐⭐⭐</div>
                <p class="testimonial-quote">
                    "The daily tasks feature is genius! I earn extra income just by spending 10 minutes a day. My returns have consistently exceeded my expectations month after month."
                </p>
                <div class="testimonial-author">
                    <img src="https://ui-avatars.com/api/?name=Sarah+Martinez&background=1E3A8A&color=fff&size=48" alt="Sarah Martinez" class="author-photo">
                    <div class="author-info">
                        <h4 class="author-name">Sarah Martinez</h4>
                        <p class="author-role">Elite Tier Investor</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-card-modern">
                <div class="testimonial-stars">⭐⭐⭐⭐⭐</div>
                <p class="testimonial-quote">
                    "As a busy professional, I needed a hands-off investment solution. Smart System delivers exactly that. The AI trading handles everything while I watch my wealth grow."
                </p>
                <div class="testimonial-author">
                    <img src="https://ui-avatars.com/api/?name=Michael+Kim&background=3B82F6&color=fff&size=48" alt="Michael Kim" class="author-photo">
                    <div class="author-info">
                        <h4 class="author-name">Michael Kim</h4>
                        <p class="author-role">Professional Tier Investor</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="container">
        <div class="faq-grid">
            <div class="faq-left">
                <div class="section-badge">FAQ</div>
                <h2 class="section-title">Frequently Asked Questions</h2>
                <p class="section-description">
                    Everything you need to know about Smart System Investment. Can't find the answer you're looking for? Contact our support team.
                </p>
                <a href="{{ route('contact') }}" class="btn-outline-primary">
                    Contact Support
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M6 12L10 8L6 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </a>
            </div>
            <div class="faq-right">
                <div class="faq-accordion">
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4>How does Smart System generate returns?</h4>
                            <span class="faq-icon">+</span>
                        </div>
                        <div class="faq-answer">
                            We use advanced algorithmic trading strategies across multiple markets including forex, cryptocurrencies, and stocks. Our AI-powered systems analyze market data 24/7 to identify profitable opportunities and execute trades on your behalf.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4>What is the minimum investment amount?</h4>
                            <span class="faq-icon">+</span>
                        </div>
                        <div class="faq-answer">
                            Our Starter plan begins at just $1,000, making professional investment management accessible to everyone. We also offer Professional ($5,000) and Elite ($20,000) tiers for higher returns.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4>Can I withdraw my funds anytime?</h4>
                            <span class="faq-icon">+</span>
                        </div>
                        <div class="faq-answer">
                            Yes! We pride ourselves on flexibility. You can request withdrawals at any time, with processing typically completed within 24-48 hours for standard accounts and instantly for Elite tier members.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4>Is my investment secure?</h4>
                            <span class="faq-icon">+</span>
                        </div>
                        <div class="faq-answer">
                            Security is our top priority. We use bank-level encryption, cold storage for crypto assets, and are fully regulated by international financial authorities. Your funds are segregated and protected by insurance.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4>What are daily tasks and how do they work?</h4>
                            <span class="faq-icon">+</span>
                        </div>
                        <div class="faq-answer">
                            Daily tasks are simple activities like checking market updates, reviewing your portfolio, or completing educational modules. Each task takes just a few minutes and earns you bonus rewards on top of your regular investment returns.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section-modern">
    <div class="cta-overlay"></div>
    <div class="container">
        <div class="cta-content-modern">
            <h2 class="cta-title-modern">Ready to Start Your Investment Journey?</h2>
            <p class="cta-subtitle-modern">
                Join over 12,000 investors who are already building their financial future with Smart System Investment. Get started today with as little as $1,000.
            </p>
            <div class="cta-buttons-modern">
                <a href="{{ route('register') }}" class="btn-cta-primary">
                    Create Free Account
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M7 13L10 10L7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </a>
                <a href="{{ route('contact') }}" class="btn-cta-secondary">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M3 5C3 3.89543 3.89543 3 5 3H7.27924C7.70967 3 8.09181 3.27543 8.22792 3.68377L9.72574 8.17721C9.88311 8.64932 9.68208 9.16531 9.23875 9.39815L7.26096 10.3871C8.36312 12.3945 10.1017 14.133 12.1091 15.2352L13.098 13.2574C13.3309 12.8141 13.8469 12.613 14.319 12.7704L18.8124 14.2682C19.2208 14.4043 19.4962 14.7864 19.4962 15.217V17.5C19.4962 18.6046 18.6008 19.5 17.4962 19.5H16C8.26801 19.5 2 13.232 2 5.5V4C2 2.89543 2.89543 2 4 2H6.28299C6.71341 2 7.09556 2.27543 7.23166 2.68377L8.72949 7.17721" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Talk to an Expert
                </a>
            </div>
            <div class="cta-trust-badges">
                <div class="trust-badge-item">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
                        <path d="M6 10L8 12L14 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    No credit card required
                </div>
                <div class="trust-badge-item">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
                        <path d="M6 10L8 12L14 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Start with $1,000
                </div>
                <div class="trust-badge-item">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
                        <path d="M6 10L8 12L14 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Withdraw anytime
                </div>
            </div>
        </div>
    </div>
</section>

@endsection