@extends('layouts.app')

@section('title', 'About Us - Smart System Investment')

@section('content')

<style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&display=swap');

    :root {
        --navy:      #0A1628;
        --royal:     #1A3A8F;
        --sky:       #E8F1FB;
        --teal:      #0D7A8A;
        --ash:       #E4E9F0;
        --white:     #FFFFFF;
        --text-dark: #0D1B2A;
        --text-mid:  #3A4A5C;
        --text-soft: #6B7E95;
    }

    .about-page * { box-sizing: border-box; margin: 0; padding: 0; }
    .about-page { font-family: 'DM Sans', sans-serif; color: var(--text-dark); background: var(--white); }

    /* ── HERO ── */
    .about-hero {
        position: relative;
        height: 520px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .about-hero__bg {
        position: absolute; inset: 0;
        background:
            linear-gradient(135deg, rgba(10,22,40,.82) 0%, rgba(26,58,143,.65) 60%, rgba(13,122,138,.45) 100%),
            url('https://images.unsplash.com/photo-1551836022-d5d88e9218df?w=1600&q=80') center/cover no-repeat;
        transform: scale(1.04);
        transition: transform 8s ease-out;
    }
    .about-hero:hover .about-hero__bg { transform: scale(1); }

    /* geometric accent lines */
    .about-hero::before {
        content: '';
        position: absolute; inset: 0;
        background: repeating-linear-gradient(
            -45deg,
            transparent,
            transparent 60px,
            rgba(255,255,255,.025) 60px,
            rgba(255,255,255,.025) 61px
        );
        pointer-events: none;
    }

    .about-hero__content {
        position: relative; z-index: 2;
        text-align: center;
        padding: 0 1.5rem;
        animation: heroFadeUp .9s ease both;
    }
    @keyframes heroFadeUp {
        from { opacity:0; transform: translateY(28px); }
        to   { opacity:1; transform: translateY(0); }
    }

    .about-hero__eyebrow {
        display: inline-flex; align-items: center; gap: .5rem;
        font-size: .75rem; font-weight: 600; letter-spacing: .18em;
        text-transform: uppercase; color: #7EC8E3;
        background: rgba(126,200,227,.12);
        border: 1px solid rgba(126,200,227,.3);
        padding: .35rem 1rem; border-radius: 999px;
        margin-bottom: 1.4rem;
    }
    .about-hero__eyebrow span { width:6px; height:6px; border-radius:50%; background:#7EC8E3; display:block; }

    .about-hero__title {
        font-family: 'Playfair Display', serif;
        font-size: clamp(2.4rem, 5vw, 4rem);
        font-weight: 900; color: #fff;
        line-height: 1.15;
        margin-bottom: 1rem;
    }
    .about-hero__title em { font-style: italic; color: #7EC8E3; }

    .about-hero__sub {
        font-size: 1.05rem; color: rgba(255,255,255,.72);
        max-width: 520px; margin: 0 auto 2rem;
        line-height: 1.7;
    }

    .about-hero__breadcrumb {
        display: flex; align-items: center; justify-content: center; gap: .5rem;
        font-size: .82rem; color: rgba(255,255,255,.55);
    }
    .about-hero__breadcrumb a { color: #7EC8E3; text-decoration: none; }
    .about-hero__breadcrumb a:hover { text-decoration: underline; }

    /* ── WAVE DIVIDER ── */
    .wave-divider { display:block; width:100%; line-height:0; }

    /* ── SECTION SHELL ── */
    .about-section { padding: 5rem 1.5rem; }
    .about-container { max-width: 1140px; margin: 0 auto; }

    .section-label {
        font-size: .72rem; font-weight: 700; letter-spacing: .2em;
        text-transform: uppercase; color: var(--teal);
        margin-bottom: .6rem;
    }
    .section-heading {
        font-family: 'Playfair Display', serif;
        font-size: clamp(1.8rem, 3.5vw, 2.8rem);
        font-weight: 800; color: var(--navy);
        line-height: 1.2; margin-bottom: 1rem;
    }
    .section-heading span { color: var(--royal); font-style: italic; }
    .section-lead {
        font-size: 1.05rem; color: var(--text-mid);
        line-height: 1.8; max-width: 620px;
    }

    /* ── WHO WE ARE ── */
    .who-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4rem;
        align-items: center;
    }
    @media(max-width:768px){ .who-grid{ grid-template-columns:1fr; gap:2.5rem; } }

    .who-image-wrap {
        position: relative;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 24px 64px rgba(10,22,40,.18);
    }
    .who-image-wrap img { width:100%; height:400px; object-fit:cover; display:block; }
    .who-image-badge {
        position: absolute; bottom: 1.5rem; left: 1.5rem;
        background: var(--white);
        border-radius: 14px;
        padding: .8rem 1.2rem;
        box-shadow: 0 8px 24px rgba(10,22,40,.15);
        display: flex; align-items: center; gap: .8rem;
    }
    .who-image-badge__icon {
        width: 42px; height: 42px; border-radius: 10px;
        background: linear-gradient(135deg,var(--royal),var(--teal));
        display: flex; align-items:center; justify-content:center;
        font-size: 1.2rem;
    }
    .who-image-badge__label { font-size: .72rem; color: var(--text-soft); }
    .who-image-badge__value { font-size: 1.1rem; font-weight: 700; color: var(--navy); }

    .who-text { }
    .who-text .section-lead { max-width: none; margin-bottom: 2rem; }

    .who-pills {
        display: flex; flex-wrap: wrap; gap: .6rem;
        margin-bottom: 2rem;
    }
    .who-pill {
        padding: .4rem 1rem; border-radius: 999px;
        border: 1.5px solid var(--ash);
        font-size: .82rem; font-weight: 500; color: var(--royal);
        background: var(--sky);
    }

    /* ── STATS BAR ── */
    .stats-bar {
        background: var(--navy);
        padding: 3rem 1.5rem;
    }
    .stats-grid {
        max-width: 1140px; margin: 0 auto;
        display: grid; grid-template-columns: repeat(4,1fr);
        gap: 1.5rem; text-align: center;
    }
    @media(max-width:640px){ .stats-grid{ grid-template-columns: 1fr 1fr; } }

    .stat-item { padding: 1.5rem 1rem; }
    .stat-item__num {
        font-family: 'Playfair Display', serif;
        font-size: 2.6rem; font-weight: 900; color: #fff;
        line-height: 1;
    }
    .stat-item__num sup { font-size: 1.2rem; color: #7EC8E3; }
    .stat-item__label { font-size: .82rem; color: rgba(255,255,255,.55); margin-top: .4rem; letter-spacing:.06em; }
    .stat-item__divider { width:32px; height:2px; background: var(--teal); margin: .6rem auto 0; }

    /* ── PILLARS (Mission / Vision / Values) ── */
    .pillars-bg { background: var(--sky); }
    .pillars-grid {
        display: grid;
        grid-template-columns: repeat(3,1fr);
        gap: 2rem; margin-top: 3rem;
    }
    @media(max-width:768px){ .pillars-grid{ grid-template-columns:1fr; } }

    .pillar-card {
        background: var(--white);
        border-radius: 20px;
        padding: 2.4rem 2rem;
        box-shadow: 0 4px 24px rgba(10,22,40,.07);
        border-top: 4px solid transparent;
        transition: transform .25s, box-shadow .25s;
        position: relative; overflow: hidden;
    }
    .pillar-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(10,22,40,.13); }
    .pillar-card:nth-child(1){ border-top-color: var(--royal); }
    .pillar-card:nth-child(2){ border-top-color: var(--teal); }
    .pillar-card:nth-child(3){ border-top-color: #F59E0B; }

    .pillar-card::after {
        content: '';
        position: absolute; top:-40px; right:-40px;
        width:120px; height:120px; border-radius:50%;
        background: var(--sky); opacity: .6;
        pointer-events:none;
    }

    .pillar-icon {
        width: 54px; height: 54px; border-radius: 14px;
        display: flex; align-items:center; justify-content:center;
        font-size: 1.5rem; margin-bottom: 1.2rem;
    }
    .pillar-card:nth-child(1) .pillar-icon { background: rgba(26,58,143,.1); }
    .pillar-card:nth-child(2) .pillar-icon { background: rgba(13,122,138,.1); }
    .pillar-card:nth-child(3) .pillar-icon { background: rgba(245,158,11,.1); }

    .pillar-card h3 {
        font-family: 'Playfair Display', serif;
        font-size: 1.3rem; font-weight: 700; color: var(--navy);
        margin-bottom: .7rem;
    }
    .pillar-card p { font-size: .95rem; color: var(--text-mid); line-height: 1.75; }

    /* ── LEADERSHIP ── */
    .leadership-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px,1fr));
        gap: 2rem; margin-top: 3rem;
    }
    .leader-card {
        background: var(--white);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(10,22,40,.08);
        transition: transform .25s, box-shadow .25s;
    }
    .leader-card:hover { transform: translateY(-5px); box-shadow: 0 14px 40px rgba(10,22,40,.14); }

    .leader-card__img {
        height: 220px; background: var(--sky);
        display: flex; align-items:center; justify-content:center;
        font-size: 4rem;
        position: relative;
    }
    .leader-card__img::after {
        content:'';
        position:absolute; bottom:0; left:0; right:0; height:50%;
        background: linear-gradient(to top, rgba(10,22,40,.35), transparent);
    }

    .leader-card__body { padding: 1.4rem 1.5rem; }
    .leader-card__name { font-family:'Playfair Display',serif; font-size:1.15rem; font-weight:700; color:var(--navy); }
    .leader-card__role { font-size:.8rem; color:var(--teal); font-weight:600; letter-spacing:.06em; text-transform:uppercase; margin:.2rem 0 .8rem; }
    .leader-card__bio { font-size:.88rem; color:var(--text-mid); line-height:1.65; }

    /* ── STRATEGY ── */
    .strategy-bg { background: linear-gradient(135deg, var(--navy) 0%, var(--royal) 100%); }
    .strategy-grid {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 3rem; align-items: center;
    }
    @media(max-width:768px){ .strategy-grid{ grid-template-columns:1fr; } }

    .strategy-text .section-heading { color: #fff; }
    .strategy-text .section-label { color: #7EC8E3; }
    .strategy-text .section-lead { color: rgba(255,255,255,.72); max-width: none; margin-bottom: 2rem; }

    .strategy-items { display:flex; flex-direction:column; gap:1rem; }
    .strategy-item {
        display: flex; align-items: flex-start; gap: 1rem;
        background: rgba(255,255,255,.07);
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 14px;
        padding: 1.2rem 1.4rem;
        transition: background .2s;
    }
    .strategy-item:hover { background: rgba(255,255,255,.13); }
    .strategy-item__icon {
        width: 38px; height: 38px; flex-shrink:0;
        background: rgba(126,200,227,.15);
        border-radius: 10px;
        display:flex; align-items:center; justify-content:center;
        font-size: 1.1rem;
    }
    .strategy-item h4 { font-size:.95rem; font-weight:600; color:#fff; margin-bottom:.3rem; }
    .strategy-item p { font-size:.85rem; color:rgba(255,255,255,.6); line-height:1.6; }

    /* ── AWARDS ── */
    .awards-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
        gap: 1.5rem; margin-top: 3rem;
    }
    .award-card {
        border: 1.5px solid var(--ash);
        border-radius: 16px;
        padding: 1.8rem 1.5rem;
        text-align: center;
        background: var(--white);
        transition: border-color .2s, box-shadow .2s;
    }
    .award-card:hover { border-color: var(--royal); box-shadow: 0 8px 24px rgba(26,58,143,.1); }
    .award-card__icon { font-size: 2rem; margin-bottom: .8rem; }
    .award-card__title { font-size:.9rem; font-weight:700; color:var(--navy); margin-bottom:.3rem; }
    .award-card__year { font-size:.78rem; color:var(--text-soft); }

    /* ── TRANSPARENCY ── */
    .transparency-bg { background: var(--sky); }
    .transparency-grid {
        display: grid; grid-template-columns: repeat(3,1fr);
        gap: 1.5rem; margin-top: 3rem;
    }
    @media(max-width:768px){ .transparency-grid{ grid-template-columns:1fr; } }

    .trans-card {
        background: var(--white);
        border-radius: 16px;
        padding: 2rem;
        box-shadow: 0 2px 12px rgba(10,22,40,.06);
        border-left: 4px solid var(--teal);
    }
    .trans-card__icon { font-size: 1.6rem; margin-bottom:.8rem; }
    .trans-card h4 { font-size:1rem; font-weight:700; color:var(--navy); margin-bottom:.5rem; }
    .trans-card p { font-size:.9rem; color:var(--text-mid); line-height:1.7; }

    /* ── REVENUE MODEL ── */
    .rev-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
        gap: 1.5rem; margin-top: 3rem;
    }
    .rev-card {
        background: var(--white);
        border-radius: 16px;
        padding: 1.8rem;
        box-shadow: 0 4px 20px rgba(10,22,40,.07);
        position: relative; overflow: hidden;
    }
    .rev-card__num {
        position:absolute; top:1rem; right:1.2rem;
        font-family:'Playfair Display',serif;
        font-size:3.5rem; font-weight:900;
        color: var(--sky); line-height:1;
    }
    .rev-card__icon { font-size:1.6rem; margin-bottom:.8rem; }
    .rev-card h4 { font-size:.95rem; font-weight:700; color:var(--navy); margin-bottom:.4rem; }
    .rev-card p { font-size:.85rem; color:var(--text-mid); line-height:1.65; }

    /* ── CTA STRIP ── */
    .cta-strip {
        background: var(--royal);
        padding: 4rem 1.5rem;
        text-align: center;
    }
    .cta-strip h2 {
        font-family:'Playfair Display',serif;
        font-size: clamp(1.8rem,3vw,2.6rem);
        font-weight:800; color:#fff; margin-bottom:.8rem;
    }
    .cta-strip p { color:rgba(255,255,255,.72); font-size:1rem; margin-bottom:2rem; }
    .cta-btns { display:flex; gap:1rem; justify-content:center; flex-wrap:wrap; }

    .btn-primary {
        display:inline-flex; align-items:center; gap:.5rem;
        background:#fff; color:var(--royal);
        padding:.85rem 2rem; border-radius:10px;
        font-weight:700; font-size:.95rem;
        text-decoration:none; transition: transform .2s, box-shadow .2s;
        box-shadow: 0 4px 14px rgba(0,0,0,.15);
    }
    .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.2); }

    .btn-outline {
        display:inline-flex; align-items:center; gap:.5rem;
        border:2px solid rgba(255,255,255,.6); color:#fff;
        padding:.85rem 2rem; border-radius:10px;
        font-weight:600; font-size:.95rem;
        text-decoration:none; transition: border-color .2s, background .2s;
    }
    .btn-outline:hover { border-color:#fff; background:rgba(255,255,255,.1); }
</style>

<div class="about-page">

    {{-- ══ HERO ══ --}}
    <section class="about-hero">
        <div class="about-hero__bg"></div>
        <div class="about-hero__content">
            <div class="about-hero__eyebrow"><span></span>Trusted Since 2018<span></span></div>
            <h1 class="about-hero__title">About <em>Smart System</em><br>Investment</h1>
            <p class="about-hero__sub">Where structured wealth meets transparent growth — empowering investors with integrity, clarity, and proven results.</p>
            <div class="about-hero__breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4.5 2.5L7.5 6L4.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>About Us</span>
            </div>
        </div>
    </section>

    {{-- wave --}}
    <svg class="wave-divider" viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0,0 C360,60 1080,0 1440,60 L1440,0 L0,0 Z" fill="#0A1628"/>
        <path d="M0,60 C360,0 1080,60 1440,0 L1440,60 Z" fill="#ffffff"/>
    </svg>

    {{-- ══ WHO WE ARE ══ --}}
    <section class="about-section">
        <div class="about-container">
            <div class="who-grid">
                <div class="who-image-wrap">
                    <img src="https://images.unsplash.com/photo-1600880292203-757bb62b4baf?w=800&q=80" alt="Our team at work">
                    <div class="who-image-badge">
                        <div class="who-image-badge__icon">📈</div>
                        <div>
                            <div class="who-image-badge__label">Portfolio Growth</div>
                            <div class="who-image-badge__value">+14.28% YTD</div>
                        </div>
                    </div>
                </div>

                <div class="who-text">
                    <p class="section-label">Who We Are</p>
                    <h2 class="section-heading">Built on <span>Integrity</span>,<br>Driven by Results</h2>
                    <p class="section-lead" style="margin-bottom:1.5rem;">
                        Smart System Investment was founded with a clear mission: to democratize access to professional-grade investment opportunities. We combine cutting-edge technology with proven strategies to deliver consistent, transparent returns.
                    </p>
                    <p class="section-lead">
                        Our team of seasoned financial experts, technologists, and compliance officers works every day to ensure your capital is structured for maximum growth — within carefully defined risk parameters.
                    </p>
                    <div class="who-pills" style="margin-top:1.8rem;">
                        <span class="who-pill">🏦 SEC Compliant</span>
                        <span class="who-pill">🔒 Fund Segregation</span>
                        <span class="who-pill">📊 Real-time Reporting</span>
                        <span class="who-pill">🌍 Global Reach</span>
                        <span class="who-pill">⚡ Algo-Assisted Trading</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══ STATS BAR ══ --}}
    <div class="stats-bar">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-item__num"><sup>$</sup>4.7B</div>
                <div class="stat-item__divider"></div>
                <div class="stat-item__label">Total Assets Managed</div>
            </div>
            <div class="stat-item">
                <div class="stat-item__num">12K<sup>+</sup></div>
                <div class="stat-item__divider"></div>
                <div class="stat-item__label">Active Investors</div>
            </div>
            <div class="stat-item">
                <div class="stat-item__num">7<sup>+</sup></div>
                <div class="stat-item__divider"></div>
                <div class="stat-item__label">Years of Operation</div>
            </div>
            <div class="stat-item">
                <div class="stat-item__num">14<sup>%</sup></div>
                <div class="stat-item__divider"></div>
                <div class="stat-item__label">Avg. Annual Payout Rate</div>
            </div>
        </div>
    </div>

    {{-- ══ MISSION / VISION / VALUES ══ --}}
    <section class="about-section pillars-bg">
        <div class="about-container">
            <div style="text-align:center; max-width:560px; margin:0 auto 0;">
                <p class="section-label">Our Foundation</p>
                <h2 class="section-heading">What <span>Drives</span> Us</h2>
            </div>
            <div class="pillars-grid">
                <div class="pillar-card">
                    <div class="pillar-icon">🎯</div>
                    <h3>Our Mission</h3>
                    <p>Empowering individuals and institutions to achieve lasting financial freedom through disciplined, data-driven investment management — accessible to everyone.</p>
                </div>
                <div class="pillar-card">
                    <div class="pillar-icon">👁️</div>
                    <h3>Our Vision</h3>
                    <p>To be the world's most trusted and transparent investment platform — where every investor, regardless of size, has access to institutional-grade performance.</p>
                </div>
                <div class="pillar-card">
                    <div class="pillar-icon">⚖️</div>
                    <h3>Our Values</h3>
                    <p>Transparency in every transaction. Integrity in every decision. Client success as our ultimate benchmark — always above short-term firm interests.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ══ OUR HISTORY ══ --}}
    <section class="about-section">
        <div class="about-container">
            <p class="section-label">Our Journey</p>
            <h2 class="section-heading" style="margin-bottom:3rem;">From <span>Startup</span> to Industry Leader</h2>

            <div style="position:relative; padding-left:2.5rem; border-left:3px solid var(--ash);">
                @foreach([
                    ['2018','Founded in Lagos — launched with 50 seed investors and a vision to democratize professional investing.','var(--royal)'],
                    ['2019','Crossed $10M AUM. Introduced proprietary risk-scoring algorithm. First annual investor report published.','var(--teal)'],
                    ['2021','Expanded to diaspora market. Launched mobile platform. Partnerships with 3 Tier-1 banks.','var(--royal)'],
                    ['2023','Surpassed 10,000 active investors. Achieved ISO 27001 certification for data security.','var(--teal)'],
                    ['2025','Crossed $4.7B AUM. Launched AI-assisted portfolio optimization. Won FinTech Africa Award.','var(--royal)'],
                ] as $event)
                <div style="position:relative; margin-bottom:2.8rem;">
                    <div style="position:absolute; left:-3rem; top:.1rem; width:20px; height:20px; border-radius:50%; background:{{ $event[2] }}; border:3px solid white; box-shadow:0 0 0 3px {{ $event[2] }}22;"></div>
                    <span style="display:inline-block; font-size:.78rem; font-weight:700; letter-spacing:.1em; color:{{ $event[2] }}; margin-bottom:.3rem;">{{ $event[0] }}</span>
                    <p style="font-size:.97rem; color:var(--text-mid); line-height:1.7;">{{ $event[1] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══ LEADERSHIP ══ --}}
    <section class="about-section" style="background:var(--sky);">
        <div class="about-container">
            <div style="text-align:center; max-width:540px; margin:0 auto;">
                <p class="section-label">Leadership</p>
                <h2 class="section-heading">Meet the <span>Team</span> Behind the Platform</h2>
            </div>
            <div class="leadership-grid">
                @foreach([
                    ['👨‍💼','Tony Love','Chief Executive Officer','Veteran finance strategist with 20+ years across hedge funds and private equity. Architect of Smart System\'s investment framework.'],
                    ['👩‍💻','Amara Osei','Chief Technology Officer','Former Google engineer. Built the proprietary algo-trading engine that powers our portfolio optimization.'],
                    ['👨‍⚖️','David Mensah','Chief Compliance Officer','Ex-SEC regulatory counsel. Ensures every operation meets international financial compliance standards.'],
                    ['👩‍💹','Fatima Al-Rashid','Chief Investment Officer','Quantitative analyst with expertise in emerging market equities and fixed-income derivatives.'],
                ] as $leader)
                <div class="leader-card">
                    <div class="leader-card__img">{{ $leader[0] }}</div>
                    <div class="leader-card__body">
                        <div class="leader-card__name">{{ $leader[1] }}</div>
                        <div class="leader-card__role">{{ $leader[2] }}</div>
                        <div class="leader-card__bio">{{ $leader[3] }}</div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══ OUR STRATEGY ══ --}}
    <section class="about-section strategy-bg">
        <div class="about-container">
            <div class="strategy-grid">
                <div class="strategy-text">
                    <p class="section-label">Our Strategy</p>
                    <h2 class="section-heading">How We <span style="color:#7EC8E3; font-style:italic;">Consistently</span> Deliver</h2>
                    <p class="section-lead">Our multi-layer investment strategy is built to weather volatility while capturing growth across market cycles — combining disciplined quantitative analysis with human expertise.</p>
                </div>
                <div class="strategy-items">
                    @foreach([
                        ['🔬','Quantitative Research','Data-driven models screen thousands of opportunities daily, filtering for risk-adjusted returns.'],
                        ['⚖️','Dynamic Asset Allocation','Portfolios rebalance automatically based on real-time market signals and macro indicators.'],
                        ['🛡️','Drawdown Control','Hard stop-loss parameters limit peak-to-trough losses, protecting capital during volatility.'],
                        ['📡','Real-time Monitoring','24/7 algorithmic surveillance with human oversight flags anomalies before they become risks.'],
                    ] as $s)
                    <div class="strategy-item">
                        <div class="strategy-item__icon">{{ $s[0] }}</div>
                        <div>
                            <h4>{{ $s[1] }}</h4>
                            <p>{{ $s[2] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ══ AWARDS & ACHIEVEMENTS ══ --}}
    <section class="about-section">
        <div class="about-container">
            <div style="text-align:center;">
                <p class="section-label">Recognition</p>
                <h2 class="section-heading">Awards & <span>Achievements</span></h2>
            </div>
            <div class="awards-grid">
                @foreach([
                    ['🏆','Best FinTech Platform','Africa FinTech Awards — 2024'],
                    ['🥇','Top Investment Manager','West Africa Finance Summit — 2023'],
                    ['⭐','5-Star Transparency Rating','Global Investor Trust Index — 2023'],
                    ['🎖️','ISO 27001 Certified','Information Security — 2022'],
                    ['📜','SEC Licensed','Regulatory Compliance — Since 2018'],
                ] as $a)
                <div class="award-card">
                    <div class="award-card__icon">{{ $a[0] }}</div>
                    <div class="award-card__title">{{ $a[1] }}</div>
                    <div class="award-card__year">{{ $a[2] }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══ HOW WE GENERATE REVENUE ══ --}}
    <section class="about-section" style="background:var(--sky);">
        <div class="about-container">
            <p class="section-label">Business Model</p>
            <h2 class="section-heading">How We <span>Generate Revenue</span></h2>
            <div class="rev-grid">
                @foreach([
                    ['💼','Management Fee','A small annual percentage of AUM — our incentive is directly aligned with growing your portfolio.'],
                    ['📈','Performance Fee','We share in success. A performance fee applies only when returns exceed the agreed benchmark.'],
                    ['🔄','Spread Optimization','Institutional pricing on trades reduces transaction costs — a fraction of savings flows to the firm.'],
                    ['🤝','B2B Advisory','Corporate treasury management and institutional advisory mandates contribute recurring revenue.'],
                ] as $idx => $r)
                <div class="rev-card">
                    <div class="rev-card__num">0{{ $idx+1 }}</div>
                    <div class="rev-card__icon">{{ $r[0] }}</div>
                    <h4>{{ $r[1] }}</h4>
                    <p>{{ $r[2] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══ TRANSPARENCY ══ --}}
    <section class="about-section transparency-bg">
        <div class="about-container">
            <div style="text-align:center; max-width:560px; margin:0 auto;">
                <p class="section-label">Transparency First</p>
                <h2 class="section-heading">Why You Can <span>Trust</span> Us</h2>
            </div>
            <div class="transparency-grid">
                <div class="trans-card">
                    <div class="trans-card__icon">⚠️</div>
                    <h4>Risk Disclosure</h4>
                    <p>All investments carry risk. We publish full risk metrics — including worst-case drawdown scenarios — so you can make fully informed decisions before committing capital.</p>
                </div>
                <div class="trans-card">
                    <div class="trans-card__icon">🚫</div>
                    <h4>No Guarantee Statement</h4>
                    <p>We do not promise fixed returns. Past performance is presented honestly — not as a guarantee of future results. Any platform that promises guaranteed returns should raise concern.</p>
                </div>
                <div class="trans-card">
                    <div class="trans-card__icon">📋</div>
                    <h4>Compliance Note</h4>
                    <p>Smart System operates under applicable securities regulation. All fund activities are audited quarterly by an independent third-party firm and available to investors on request.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ══ WHY WE'RE DIFFERENT ══ --}}
    <section class="about-section">
        <div class="about-container">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:3rem; align-items:center;">
                <div>
                    <p class="section-label">Why Choose Us</p>
                    <h2 class="section-heading">What Makes Us <span>Different</span></h2>
                    <p class="section-lead" style="margin-bottom:2rem;">In a crowded market, Smart System stands apart through radical transparency, institutional-grade infrastructure, and a genuine alignment of interests with our investors.</p>

                    @foreach([
                        ['🔍','Full Audit Trail','Every transaction is logged, timestamped, and available for investor review.'],
                        ['🏛️','Institutional Infrastructure','We use the same tools and processes as top-tier asset managers — at retail-accessible minimums.'],
                        ['📱','Real-time Reporting','Live dashboard visibility into your portfolio performance, not monthly PDFs.'],
                        ['🤝','Aligned Incentives','We only earn performance fees when you win — our success is your success.'],
                    ] as $d)
                    <div style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1.2rem;">
                        <div style="width:40px; height:40px; flex-shrink:0; background:var(--sky); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">{{ $d[0] }}</div>
                        <div>
                            <div style="font-weight:700; color:var(--navy); font-size:.95rem;">{{ $d[1] }}</div>
                            <div style="font-size:.88rem; color:var(--text-mid); margin-top:.2rem;">{{ $d[2] }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div style="background:var(--sky); border-radius:24px; padding:2.5rem; border:1.5px solid var(--ash);">
                    <p style="font-size:.78rem; font-weight:700; letter-spacing:.15em; color:var(--teal); text-transform:uppercase; margin-bottom:1rem;">Historical Performance</p>
                    <div style="display:flex; flex-direction:column; gap:.8rem;">
                        @foreach([
                            ['2024','14.28%','var(--royal)'],
                            ['2025','11.88%','var(--teal)'],
                        ] as $perf)
                        <div style="background:var(--white); border-radius:12px; padding:1rem 1.2rem; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:.9rem; color:var(--text-mid);">Annual Return {{ $perf[0] }}</span>
                            <span style="font-size:1.2rem; font-weight:800; color:{{ $perf[2] }};">+{{ $perf[1] }}</span>
                        </div>
                        @endforeach
                        <div style="background:var(--white); border-radius:12px; padding:1rem 1.2rem;">
                            <p style="font-size:.78rem; color:var(--text-soft); margin-bottom:.5rem;">Monthly Returns (2024)</p>
                            <div style="display:flex; gap:4px; align-items:flex-end; height:40px;">
                                @foreach([70,85,60,90,75,95,80,88,72,91,84,78] as $h)
                                <div style="flex:1; background:var(--royal); border-radius:3px 3px 0 0; height:{{ $h }}%; opacity:.7; transition:opacity .2s;"
                                     onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.7"></div>
                                @endforeach
                            </div>
                        </div>
                        <p style="font-size:.72rem; color:var(--text-soft); text-align:center; margin-top:.4rem;">Past performance does not guarantee future results.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══ CTA ══ --}}
    <div class="cta-strip">
        <h2>Ready to Grow With Us?</h2>
        <p>Join over 12,000 investors building structured, transparent wealth on Smart System.</p>
        <div class="cta-btns">
            <a href="{{ route('register') }}" class="btn-primary">🚀 Create Account</a>
            <a href="{{ route('login') }}" class="btn-outline">🔑 Investor Login</a>
        </div>
    </div>

</div>
@endsection