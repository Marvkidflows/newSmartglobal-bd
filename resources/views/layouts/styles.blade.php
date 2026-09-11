<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --primary: #003366; /* Navy Blue */
        --primary-light: #4169E1; /* Royal Blue */
        --primary-dark: #001122; /* Dark Navy */
        --accent: #87CEEB; /* Sky Blue */
        --accent-light: #A5D8FF; /* Light Sky Blue */
        --bg-primary: #F0F8FF; /* Alice Blue (light background) */
        --bg-secondary: #ffffff; /* White */
        --text-primary: #1a1a1a; /* Dark Gray */
        --text-secondary: #666666; /* Medium Gray */
        --text-light: #999999; /* Light Gray */
        --border: #D3D3D3; /* Light Gray */
        --success: #28a745; /* Green for success */
        --warning: #ffc107; /* Yellow for warning */
        --danger: #dc3545; /* Red for danger */
        --shadow: rgba(0, 51, 102, 0.08); /* Navy shadow */
        --shadow-lg: rgba(0, 51, 102, 0.15); /* Navy shadow large */
    }

    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--bg-primary);
        color: var(--text-primary);
        line-height: 1.6;
        overflow-x: hidden;
    }

    .app {
        min-height: 100vh;
    }

    /* Navigation */
    .navbar {
        background: var(--bg-secondary);
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 1000;
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.95);
    }

    .nav-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.2rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .logo {
        font-family: 'Crimson Pro', serif;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--primary);
        letter-spacing: -0.5px;
        text-decoration: none;
    }

    .logo span {
        color: var(--accent);
    }

    .nav-menu {
        display: flex;
        gap: 2.5rem;
        list-style: none;
        align-items: center;
    }

    .nav-link {
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 500;
        font-size: 0.95rem;
        transition: color 0.3s;
        position: relative;
    }

    .nav-link:hover {
        color: var(--primary);
    }

    .nav-link.active::after {
        content: '';
        position: absolute;
        bottom: -5px;
        left: 0;
        width: 100%;
        height: 2px;
        background: var(--accent);
    }

    .nav-buttons {
        display: flex;
        gap: 1rem;
    }

    /* Buttons */
    .btn {
        padding: 0.75rem 1.8rem;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 0.95rem;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: 0 2px 8px var(--shadow);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px var(--shadow-lg);
    }

    .btn-secondary {
        background: transparent;
        color: var(--primary);
        border: 2px solid var(--primary);
    }

    .btn-secondary:hover {
        background: var(--primary);
        color: white;
    }

    .btn-accent {
        background: var(--accent);
        color: var(--primary-dark);
    }

    .btn-accent:hover {
        background: var(--accent-light);
    }

    .btn-small {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }

    /* Hero Section */
    .hero {
        position: relative;
        padding: 6rem 2rem;
        background: linear-gradient(135deg, var(--bg-primary) 0%, #e6f3ff 100%);
        overflow: hidden;
    }

    .hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 800px;
        height: 800px;
        background: radial-gradient(circle, rgba(135, 206, 235, 0.1) 0%, transparent 70%);
        border-radius: 50%;
    }

    .hero-container {
        max-width: 1400px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4rem;
        align-items: center;
        position: relative;
    }

    .hero-content h1 {
        font-family: 'Crimson Pro', serif;
        font-size: 3.5rem;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 1.5rem;
        color: var(--primary-dark);
    }

    .hero-content .highlight {
        color: var(--accent);
    }

    .hero-content p {
        font-size: 1.2rem;
        color: var(--text-secondary);
        margin-bottom: 2.5rem;
        line-height: 1.8;
    }

    .hero-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 2rem;
        margin-top: 3rem;
    }

    .stat-item {
        text-align: center;
        padding: 1.5rem;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 12px var(--shadow);
    }

    .stat-value {
        font-family: 'Crimson Pro', serif;
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--primary);
        display: block;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin-top: 0.5rem;
    }

    /* Content Sections */
    .section {
        padding: 5rem 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    .section-header {
        text-align: center;
        margin-bottom: 4rem;
    }

    .section-title {
        font-family: 'Crimson Pro', serif;
        font-size: 2.8rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 1rem;
    }

    .section-subtitle {
        font-size: 1.1rem;
        color: var(--text-secondary);
        max-width: 600px;
        margin: 0 auto;
    }

    /* Dashboard */
    .dashboard {
        padding: 2rem;
        min-height: 100vh;
        background: var(--bg-primary);
    }

    .dashboard-header {
        background: white;
        padding: 2rem;
        border-radius: 16px;
        margin-bottom: 2rem;
        box-shadow: 0 2px 12px var(--shadow);
    }

    .welcome-text {
        font-family: 'Crimson Pro', serif;
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 0.5rem;
    }

    .dashboard-subtitle {
        color: var(--text-secondary);
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .dashboard-card {
        background: white;
        padding: 2rem;
        border-radius: 16px;
        box-shadow: 0 2px 12px var(--shadow);
        transition: transform 0.3s;
    }

    .dashboard-card:hover {
        transform: translateY(-4px);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .card-icon {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
    }

    .card-amount {
        font-family: 'Crimson Pro', serif;
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin: 1rem 0 0.5rem;
    }

    .card-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .card-change {
        color: var(--success);
        font-size: 0.9rem;
        font-weight: 600;
    }

    /* Forms */
    .form-container {
        max-width: 500px;
        margin: 4rem auto;
        background: white;
        padding: 3rem;
        border-radius: 16px;
        box-shadow: 0 8px 32px var(--shadow-lg);
    }

    .form-title {
        font-family: 'Crimson Pro', serif;
        font-size: 2.2rem;
        font-weight: 700;
        color: var(--primary-dark);
        text-align: center;
        margin-bottom: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
        font-weight: 600;
    }

    .form-input {
        width: 100%;
        padding: 0.9rem;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 1rem;
        transition: all 0.3s;
        font-family: 'DM Sans', sans-serif;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
    }

    .form-link {
        text-align: center;
        margin-top: 1.5rem;
        color: var(--text-secondary);
    }

    .form-link a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
    }

    .form-link a:hover {
        text-decoration: underline;
    }

    /* Tables */
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }

    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    th {
        background: var(--bg-primary);
        font-weight: 700;
        color: var(--text-secondary);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    tr:hover {
        background: var(--bg-primary);
    }

    /* Alerts */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        font-weight: 500;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    /* Status Badges */
    .badge {
        display: inline-block;
        padding: 0.35rem 0.85rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-success {
        background: #d4edda;
        color: #155724;
    }

    .badge-warning {
        background: #fff3cd;
        color: #856404;
    }

    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .hero-container {
            grid-template-columns: 1fr;
            text-align: center;
        }

        .hero-content h1 {
            font-size: 2.5rem;
        }

        .nav-menu {
            display: none;
        }

        .section-title {
            font-size: 2rem;
        }

        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Animations */
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: slideIn 0.6s ease-out;
    }

    /* ============================================
   MODERN HOMEPAGE STYLES
   Add this to the BOTTOM of layouts/styles.blade.php
   ============================================ */

/* Container */
.container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 2rem;
}

/* Hero Section */
.hero-modern {
    position: relative;
    min-height: 650px;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 58, 138, 0.9) 100%), 
                url('https://images.unsplash.com/photo-1551836022-deb4988cc6c0?w=1920&h=1080&fit=crop') center/cover;
    padding: 6rem 0 4rem;
}

.hero-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="grid" width="50" height="50" patternUnits="userSpaceOnUse"><path d="M 50 0 L 0 0 0 50" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="1"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
    pointer-events: none;
}

.hero-content {
    position: relative;
    z-index: 2;
}

.hero-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 4rem;
    align-items: center;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1.2rem;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 50px;
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: #10B981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.2); }
}

.hero-title {
    font-size: 3.5rem;
    font-weight: 700;
    line-height: 1.15;
    color: white;
    margin-bottom: 1.5rem;
    font-family: 'Crimson Pro', serif;
}

.highlight-blue {
    background: linear-gradient(135deg, #60A5FA 0%, #93C5FD 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.hero-subtitle {
    font-size: 1.125rem;
    line-height: 1.8;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 2.5rem;
    max-width: 580px;
}

.hero-actions {
    display: flex;
    align-items: center;
    gap: 2rem;
    flex-wrap: wrap;
}

.btn-hero-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, #16A34A 0%, #15803D 100%);
    color: white;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 20px rgba(22, 163, 74, 0.4);
}

.btn-hero-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(22, 163, 74, 0.6);
}

.hero-reviews {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.review-avatars {
    display: flex;
    align-items: center;
}

.review-avatars img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid white;
    margin-left: -10px;
}

.review-avatars img:first-child {
    margin-left: 0;
}

.review-text {
    color: white;
}

.stars {
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
}

.review-text span {
    font-size: 0.85rem;
    opacity: 0.9;
}

.hero-card {
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.card-description {
    color: #64748B;
    line-height: 1.7;
    margin-bottom: 2rem;
}

.btn-card-contact {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.75rem;
    background: linear-gradient(135deg, #16A34A 0%, #15803D 100%);
    color: white;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-card-contact:hover {
    transform: translateX(4px);
}

/* Stats Section */
.stats-section {
    background: linear-gradient(135deg, #0F766E 0%, #14B8A6 100%);
    padding: 3rem 0;
    color: white;
}

.stats-grid-modern {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2rem;
    margin-bottom: 3rem;
}

.stat-card {
    text-align: center;
}

.stat-number {
    font-size: 3rem;
    font-weight: 700;
    font-family: 'Crimson Pro', serif;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 1rem;
    opacity: 0.9;
}

.collaborate-section {
    text-align: center;
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.collaborate-text {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 2rem;
}

.highlight-number {
    color: #FDE68A;
}

.company-logos {
    display: flex;
    justify-content: center;
    gap: 3rem;
    flex-wrap: wrap;
}

.logo-item {
    font-weight: 600;
    font-size: 1.1rem;
    opacity: 0.9;
}

/* About Section */
.about-section {
    padding: 6rem 0;
    background: white;
}

.about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5rem;
    align-items: center;
}

.section-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    background: #DBEAFE;
    color: #2563EB;
    border-radius: 50px;
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 1rem;
}

.section-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 1.5rem;
    font-family: 'Crimson Pro', serif;
    line-height: 1.2;
}

.section-description {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #64748B;
    margin-bottom: 2rem;
}

.about-features {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.feature-item {
    display: flex;
    gap: 1rem;
}

.feature-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.feature-icon.blue {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
    color: white;
}

.feature-icon.green {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
}

.feature-icon.purple {
    background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
    color: white;
}

.feature-content h4 {
    font-weight: 700;
    color: #1E293B;
    margin-bottom: 0.25rem;
}

.feature-content p {
    font-size: 0.95rem;
    color: #64748B;
    line-height: 1.6;
}

.btn-outline-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.75rem;
    background: transparent;
    color: #2563EB;
    border: 2px solid #2563EB;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-outline-primary:hover {
    background: #2563EB;
    color: white;
}

.about-image {
    position: relative;
}

.main-image {
    width: 100%;
    height: 500px;
    object-fit: cover;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}

.floating-card {
    position: absolute;
    bottom: 2rem;
    left: 2rem;
    background: white;
    padding: 1.5rem 2rem;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.floating-icon {
    font-size: 2rem;
}

.floating-info h5 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2563EB;
    font-family: 'Crimson Pro', serif;
}

.floating-info p {
    font-size: 0.85rem;
    color: #64748B;
}

/* Services Section */
.services-section {
    padding: 6rem 0;
    background: #F8FAFC;
}

.section-header-center {
    text-align: center;
    margin-bottom: 4rem;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
}

.service-card {
    background: white;
    padding: 2.5rem;
    border-radius: 16px;
    border: 1px solid #E2E8F0;
    transition: all 0.3s ease;
}

.service-card:hover {
    border-color: #2563EB;
    box-shadow: 0 10px 30px rgba(37, 99, 235, 0.1);
    transform: translateY(-4px);
}

.service-icon {
    margin-bottom: 1.5rem;
}

.service-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1E293B;
    margin-bottom: 1rem;
}

.service-description {
    color: #64748B;
    line-height: 1.7;
    margin-bottom: 1.5rem;
}

.service-link {
    color: #2563EB;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.3s ease;
}

.service-link:hover {
    color: #1E3A8A;
}

/* Plans Section */
.plans-section-modern {
    padding: 6rem 0;
    background: white;
}

.plans-grid-modern {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
}

.plan-card-modern {
    background: white;
    border: 2px solid #E2E8F0;
    border-radius: 20px;
    padding: 2.5rem;
    transition: all 0.3s ease;
    position: relative;
}

.plan-card-modern:hover {
    border-color: #2563EB;
    box-shadow: 0 20px 40px rgba(37, 99, 235, 0.15);
    transform: translateY(-8px);
}

.plan-card-modern.featured {
    border-color: #2563EB;
    box-shadow: 0 20px 40px rgba(37, 99, 235, 0.15);
}

.popular-ribbon {
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    padding: 0.5rem 1.5rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.plan-header-modern {
    margin-bottom: 2rem;
}

.plan-name-modern {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.5rem;
    font-family: 'Crimson Pro', serif;
}

.plan-subtitle-modern {
    color: #64748B;
}

.plan-price-modern {
    display: flex;
    align-items: baseline;
    gap: 0.25rem;
    margin-bottom: 1.5rem;
}

.plan-price-modern .currency {
    font-size: 1.5rem;
    color: #2563EB;
    font-weight: 600;
}

.plan-price-modern .amount {
    font-size: 3rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
}

.plan-price-modern .period {
    color: #64748B;
}

.plan-returns-modern {
    margin-bottom: 2rem;
}

.returns-badge {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: #DBEAFE;
    color: #2563EB;
    border-radius: 8px;
    font-weight: 700;
}

.returns-badge.featured {
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
}

.plan-features-modern {
    list-style: none;
    padding: 0;
    margin: 0 0 2rem 0;
}

.plan-features-modern li {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 0;
    color: #475569;
}

.plan-btn-modern {
    display: block;
    width: 100%;
    padding: 1rem;
    background: #2563EB;
    color: white;
    text-align: center;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.plan-btn-modern:hover {
    background: #1E3A8A;
    transform: translateY(-2px);
}

.plan-btn-modern.featured {
    background: linear-gradient(135deg, #16A34A 0%, #15803D 100%);
}

/* How It Works */
.how-it-works-section {
    padding: 6rem 0;
    background: #F8FAFC;
}

.steps-timeline {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0;
    align-items: start;
}

.step-item-timeline {
    grid-column: span 1;
}

.step-item-timeline:nth-child(2) {
    grid-column: 3 / 4;
}

.step-item-timeline:nth-child(4) {
    grid-column: 5 / 6;
}

.step-item-timeline:nth-child(6) {
    grid-column: 7 / 8;
}

.step-connector-line {
    height: 2px;
    background: #DBEAFE;
    margin-top: 2rem;
    position: relative;
}

.step-number-badge {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 auto 1.5rem;
    font-family: 'Crimson Pro', serif;
}

.step-content-box {
    text-align: center;
}

.step-icon-box {
    width: 80px;
    height: 80px;
    background: #DBEAFE;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
}

.step-title-box {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.75rem;
}

.step-description-box {
    font-size: 0.95rem;
    color: #64748B;
    line-height: 1.6;
}

/* Testimonials */
.testimonials-section-modern {
    padding: 6rem 0;
    background: white;
}

.testimonials-grid-modern {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
}

.testimonial-card-modern {
    background: white;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    padding: 2.5rem;
    transition: all 0.3s ease;
}

.testimonial-card-modern:hover {
    border-color: #2563EB;
    box-shadow: 0 10px 30px rgba(37, 99, 235, 0.1);
    transform: translateY(-4px);
}

.testimonial-stars {
    font-size: 1.25rem;
    margin-bottom: 1.5rem;
}

.testimonial-quote {
    color: #475569;
    line-height: 1.8;
    margin-bottom: 2rem;
    font-style: italic;
}

.testimonial-author {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.author-photo {
    width: 48px;
    height: 48px;
    border-radius: 50%;
}

.author-name {
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.25rem;
}

.author-role {
    font-size: 0.875rem;
    color: #64748B;
}

/* FAQ Section */
.faq-section {
    padding: 6rem 0;
    background: #F8FAFC;
}

.faq-grid {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: 4rem;
}

.faq-accordion {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.faq-item {
    background: white;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 1.5rem 2rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.faq-item:hover {
    border-color: #2563EB;
}

.faq-question {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.faq-question h4 {
    font-weight: 700;
    color: #1E3A8A;
}

.faq-icon {
    font-size: 1.5rem;
    color: #2563EB;
    font-weight: 300;
}

.faq-answer {
    color: #64748B;
    line-height: 1.7;
}

/* CTA Section */
.cta-section-modern {
    position: relative;
    padding: 6rem 0;
    background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
}

.cta-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
}

.cta-content-modern {
    position: relative;
    z-index: 2;
    text-align: center;
    max-width: 800px;
    margin: 0 auto;
}

.cta-title-modern {
    font-size: 2.5rem;
    font-weight: 700;
    color: white;
    margin-bottom: 1.5rem;
    font-family: 'Crimson Pro', serif;
}

.cta-subtitle-modern {
    font-size: 1.125rem;
    color: rgba(255, 255, 255, 0.9);
    margin-bottom: 2.5rem;
    line-height: 1.7;
}

.cta-buttons-modern {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-bottom: 2rem;
}

.btn-cta-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, #16A34A 0%, #15803D 100%);
    color: white;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-cta-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(22, 163, 74, 0.4);
}

.btn-cta-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-cta-secondary:hover {
    background: rgba(255, 255, 255, 0.2);
}

.cta-trust-badges {
    display: flex;
    gap: 2rem;
    justify-content: center;
    color: rgba(255, 255, 255, 0.9);
}

.trust-badge-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
}

/* Responsive */
@media (max-width: 1024px) {
    .hero-grid, .about-grid, .faq-grid {
        grid-template-columns: 1fr;
        gap: 3rem;
    }
    
    .stats-grid-modern, .services-grid, .plans-grid-modern, .testimonials-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .hero-title {
        font-size: 2.5rem;
    }
    
    .steps-timeline {
        grid-template-columns: 1fr;
    }
    
    .step-connector-line {
        display: none;
    }
}

@media (max-width: 640px) {
    .hero-title {
        font-size: 2rem;
    }
    
    .section-title {
        font-size: 2rem;
    }
    
    .cta-buttons-modern {
        flex-direction: column;
    }
    
    .cta-trust-badges {
        flex-direction: column;
        gap: 1rem;
    }
}

/* =====================================================
   MULTI-STAGE REGISTRATION STYLES
   Add this to resources/views/layouts/styles.blade.php
   ===================================================== */

/* Registration Wrapper */
.registration-wrapper {
    min-height: 100vh;
    background: linear-gradient(135deg, #F8FAFC 0%, #E5E7EB 100%);
    padding: 2rem 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.registration-container {
    max-width: 700px;
    width: 100%;
    margin: 0 auto;
}

/* Progress Bar */
.registration-progress {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 3rem;
    position: relative;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    position: relative;
    z-index: 2;
}

.step-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: white;
    border: 3px solid #E5E7EB;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.25rem;
    color: #9CA3AF;
    transition: all 0.3s ease;
}

.progress-step.active .step-circle {
    border-color: #2563EB;
    background: #2563EB;
    color: white;
}

.progress-step.completed .step-circle {
    border-color: #10B981;
    background: #10B981;
    color: white;
}

.step-label {
    font-size: 0.875rem;
    color: #64748B;
    font-weight: 500;
    text-align: center;
}

.progress-step.active .step-label {
    color: #2563EB;
    font-weight: 600;
}

.progress-step.completed .step-label {
    color: #10B981;
}

.progress-line {
    position: absolute;
    height: 3px;
    background: #E5E7EB;
    top: 25px;
    left: calc(50% + 25px);
    right: calc(-50% + 25px);
    z-index: 1;
    transition: background 0.3s ease;
}

.progress-line.completed {
    background: #10B981;
}

.progress-step:last-child .progress-line {
    display: none;
}

/* Registration Card */
.registration-card {
    background: white;
    border-radius: 20px;
    padding: 3rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
}

.registration-header {
    text-align: center;
    margin-bottom: 2.5rem;
}

.registration-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.5rem;
    font-family: 'Crimson Pro', serif;
}

.registration-subtitle {
    color: #64748B;
    font-size: 1rem;
}

/* Info Box */
.info-box {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    background: #DBEAFE;
    border-radius: 12px;
    margin-top: 1.5rem;
    border-left: 4px solid #2563EB;
}

.info-box svg {
    flex-shrink: 0;
    margin-top: 0.125rem;
}

.info-box span {
    font-size: 0.875rem;
    color: #1E3A8A;
    line-height: 1.6;
}

/* Alert */
.alert {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.alert svg {
    flex-shrink: 0;
    margin-top: 0.125rem;
}

.alert-error {
    background: #FEE2E2;
    border-left: 4px solid #EF4444;
    color: #991B1B;
}

.alert-error svg {
    stroke: #EF4444;
}

/* Form */
.registration-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.form-row {
    display: flex;
    gap: 1rem;
}

.form-row .form-group {
    flex: 1;
}

.form-label {
    font-weight: 600;
    color: #1E293B;
    font-size: 0.95rem;
}

.required {
    color: #EF4444;
}

.optional {
    color: #64748B;
    font-weight: 400;
}

.form-input {
    padding: 0.875rem 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.2s ease;
    background: white;
}

.form-input:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}

.form-input.error {
    border-color: #EF4444;
}

.form-input.error:focus {
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
}

.form-hint {
    font-size: 0.875rem;
    color: #64748B;
    font-style: italic;
}

.error-message {
    font-size: 0.875rem;
    color: #EF4444;
    font-weight: 500;
}

select.form-input {
    cursor: pointer;
}

textarea.form-input {
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
}

/* Password Input */
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.password-wrapper .form-input {
    padding-right: 3rem;
}

.password-toggle {
    position: absolute;
    right: 0.75rem;
    background: none;
    border: none;
    padding: 0.5rem;
    cursor: pointer;
    color: #64748B;
    transition: color 0.2s ease;
}

.password-toggle:hover {
    color: #2563EB;
}

.password-toggle.active {
    color: #2563EB;
}

/* Password Strength */
.password-strength {
    margin-top: 0.5rem;
}

.strength-bar {
    height: 4px;
    background: #E5E7EB;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.strength-progress {
    height: 100%;
    width: 0%;
    background: #9CA3AF;
    transition: all 0.3s ease;
}

.strength-text {
    font-size: 0.875rem;
    color: #64748B;
    font-weight: 500;
}

/* Checkbox */
.checkbox-wrapper {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.checkbox-label {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    margin-top: 0.25rem;
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
}

.checkbox-text {
    font-size: 0.95rem;
    line-height: 1.6;
    color: #475569;
}

.checkbox-text a {
    color: #2563EB;
    text-decoration: underline;
    font-weight: 500;
}

.checkbox-text a:hover {
    color: #1E3A8A;
}

.checkbox-label-large {
    padding: 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.checkbox-label-large:hover {
    border-color: #2563EB;
    background: #F8FAFC;
}

.checkbox-label-large input[type="checkbox"]:checked ~ .checkbox-text {
    color: #2563EB;
    font-weight: 600;
}

.checkbox-label-large .checkbox-text {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.checkbox-label-large .checkbox-text small {
    color: #64748B;
    font-weight: 400;
}

/* Radio Buttons */
.radio-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.radio-label {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.radio-label:hover {
    border-color: #2563EB;
    background: #F8FAFC;
}

.radio-label input[type="radio"] {
    margin-top: 0.25rem;
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
}

.radio-label input[type="radio"]:checked ~ .radio-text {
    color: #2563EB;
}

.radio-label input[type="radio"]:checked {
    accent-color: #2563EB;
}

.radio-text {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.radio-text strong {
    color: #1E293B;
    font-weight: 600;
}

.radio-text small {
    color: #64748B;
    font-size: 0.875rem;
}

/* File Upload */
.file-upload-wrapper {
    position: relative;
}

.file-input {
    display: none;
}

.file-upload-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    padding: 2rem;
    border: 2px dashed #CBD5E1;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #F8FAFC;
}

.file-upload-label:hover {
    border-color: #2563EB;
    background: #EFF6FF;
}

.file-upload-label svg {
    color: #64748B;
}

.file-upload-text {
    font-weight: 600;
    color: #1E293B;
}

.file-upload-hint {
    font-size: 0.875rem;
    color: #64748B;
}

/* File Preview */
.file-preview {
    display: none;
    margin-top: 1rem;
}

.file-preview-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #F8FAFC;
    border-radius: 8px;
    border: 1px solid #E5E7EB;
}

.file-preview-item img {
    max-width: 100px;
    border-radius: 4px;
}

.file-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.file-info strong {
    color: #1E293B;
    font-size: 0.95rem;
}

.file-info small {
    color: #64748B;
    font-size: 0.875rem;
}

.file-remove {
    padding: 0.5rem;
    background: #FEE2E2;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    color: #EF4444;
    transition: all 0.2s ease;
}

.file-remove:hover {
    background: #FCA5A5;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.875rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    transition: all 0.3s ease;
    cursor: pointer;
    border: none;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
}

.btn-full {
    width: 100%;
    padding: 1rem 1.5rem;
}

.btn-large {
    font-size: 1.1rem;
    padding: 1.25rem 2rem;
}

/* Security Section */
.security-section {
    padding: 1.5rem;
    background: #F8FAFC;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
}

.security-section-header {
    display: flex;
    align-items: start;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.security-section-header svg {
    flex-shrink: 0;
}

.security-section-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.25rem;
}

.security-section-header p {
    font-size: 0.875rem;
    color: #64748B;
}

/* PIN Input */
.pin-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.pin-input {
    font-size: 1.5rem;
    letter-spacing: 0.5rem;
    text-align: center;
    font-weight: 700;
}

/* Security Tips */
.security-tips {
    padding: 1.5rem;
    background: #F0FDF4;
    border-radius: 12px;
    border: 1px solid #BBF7D0;
}

.security-tips h4 {
    font-weight: 700;
    color: #166534;
    margin-bottom: 1rem;
}

.security-tips ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.security-tips li {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    color: #166534;
    font-size: 0.95rem;
    line-height: 1.6;
}

.security-tips li svg {
    flex-shrink: 0;
    margin-top: 0.125rem;
}

/* Notice Box */
.notice-box {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    background: #F0FDF4;
    border-radius: 8px;
    border-left: 4px solid #10B981;
    margin-top: 1rem;
}

.notice-box svg {
    flex-shrink: 0;
}

.notice-box span {
    font-size: 0.875rem;
    color: #166534;
    line-height: 1.6;
}

/* Completion Notice */
.completion-notice {
    text-align: center;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #E5E7EB;
}

.completion-notice p {
    font-size: 0.875rem;
    color: #64748B;
    line-height: 1.6;
}

/* Form Footer */
.form-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #E5E7EB;
    color: #64748B;
}

.form-footer a {
    color: #2563EB;
    font-weight: 600;
    text-decoration: none;
}

.form-footer a:hover {
    text-decoration: underline;
}
/* =====================================================
   INVESTMENT PLANS PAGE STYLES
   Add to layouts/styles.blade.php
   ===================================================== */

/* Back Button */
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: white;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    color: #1E3A8A;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-back:hover {
    border-color: #2563EB;
    background: #F3F4F6;
}

/* Plans Grid */
.plans-grid-modern {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
    margin-bottom: 3rem;
}

/* Plan Card */
.plan-card {
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    border: 2px solid #E5E7EB;
    position: relative;
    transition: all 0.3s ease;
}

.plan-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
    border-color: #2563EB;
}

.plan-card.featured {
    border-color: #2563EB;
    border-width: 3px;
    box-shadow: 0 10px 30px rgba(37, 99, 235, 0.15);
}

/* Popular Badge */
.popular-badge {
    position: absolute;
    top: -15px;
    right: 30px;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    padding: 0.5rem 1.5rem;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.875rem;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
}

/* Plan Header */
.plan-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.plan-name {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
}

.plan-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.plan-card .plan-badge {
    background: #DBEAFE;
    color: #1E3A8A;
}

.plan-card.featured .plan-badge {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    color: #92400E;
}

/* Plan ROI */
.plan-roi {
    text-align: center;
    padding: 2rem;
    background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%);
    border-radius: 16px;
    margin-bottom: 2rem;
}

.roi-percentage {
    display: block;
    font-size: 3.5rem;
    font-weight: 700;
    color: #10B981;
    font-family: 'Crimson Pro', serif;
    line-height: 1;
    margin-bottom: 0.5rem;
}

.roi-label {
    display: block;
    font-size: 0.875rem;
    color: #64748B;
    font-weight: 500;
}

/* Plan Amount */
.plan-amount {
    text-align: center;
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #F3F4F6;
}

.amount-label {
    display: block;
    font-size: 0.875rem;
    color: #64748B;
    margin-bottom: 0.5rem;
}

.amount-value {
    display: block;
    font-size: 2.5rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
    margin-bottom: 0.5rem;
}

.amount-range {
    display: block;
    font-size: 0.875rem;
    color: #9CA3AF;
}

/* Plan Duration */
.plan-duration {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    background: #F8FAFC;
    border-radius: 10px;
    margin-bottom: 2rem;
    color: #64748B;
    font-weight: 500;
}

/* Plan Features */
.plan-features h4 {
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

.plan-features ul {
    list-style: none;
    padding: 0;
    margin: 0 0 2rem 0;
}

.plan-features li {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
    color: #475569;
    font-size: 0.95rem;
}

.plan-features li svg {
    flex-shrink: 0;
    margin-top: 0.125rem;
}

/* Invest Button */
.btn-invest {
    width: 100%;
    padding: 1.25rem;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-invest:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
}

.btn-invest.featured {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
}

.btn-invest.featured:hover {
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
}

/* Risk Disclosure Banner */
.risk-disclosure-banner {
    display: flex;
    align-items: start;
    gap: 1.5rem;
    padding: 2rem;
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    border-radius: 16px;
    border: 2px solid #EF4444;
}

.risk-disclosure-banner svg {
    flex-shrink: 0;
    margin-top: 0.25rem;
}

.risk-disclosure-banner h4 {
    font-weight: 700;
    color: #991B1B;
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
}

.risk-disclosure-banner p {
    color: #7F1D1D;
    line-height: 1.6;
    font-size: 0.95rem;
}

/* Investment Modal */
.invest-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}

.invest-modal.active {
    display: flex;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2rem;
    border-bottom: 2px solid #F3F4F6;
}

.modal-header h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
}

.modal-close {
    padding: 0.5rem;
    background: #F3F4F6;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748B;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: #E5E7EB;
    color: #EF4444;
}

.modal-body {
    padding: 2rem;
}

/* Investment Summary */
.investment-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: #F8FAFC;
    border-radius: 12px;
}

.summary-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    text-align: center;
}

.summary-label {
    font-size: 0.875rem;
    color: #64748B;
}

.summary-value {
    font-weight: 700;
    color: #1E3A8A;
    font-size: 1.1rem;
}

.summary-value.roi-value {
    color: #10B981;
}

/* Form Input Modern */
.form-input-modern {
    width: 100%;
    padding: 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    font-size: 1.1rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.form-input-modern:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}

/* Returns Preview */
.returns-preview {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.returns-preview h4 {
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 1rem;
}

.returns-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.return-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    text-align: center;
}

.return-label {
    font-size: 0.875rem;
    color: #64748B;
}

.return-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
}

.return-value.profit {
    color: #10B981;
}

.return-value.total {
    color: #2563EB;
}

/* Risk Warning */
.risk-warning {
    display: flex;
    align-items: start;
    gap: 1rem;
    padding: 1.25rem;
    background: #FEF3C7;
    border-radius: 10px;
    border: 2px solid #F59E0B;
}

.risk-warning input[type="checkbox"] {
    margin-top: 0.25rem;
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.risk-warning label {
    font-size: 0.875rem;
    color: #92400E;
    line-height: 1.6;
    cursor: pointer;
}

/* Modal Footer */
.modal-footer {
    display: flex;
    gap: 1rem;
    padding: 2rem;
    border-top: 2px solid #F3F4F6;
}

.modal-footer .btn-secondary,
.modal-footer .btn-primary {
    flex: 1;
    padding: 1rem;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-footer .btn-secondary {
    background: #F3F4F6;
    border: 2px solid #E5E7EB;
    color: #64748B;
}

.modal-footer .btn-secondary:hover {
    background: #E5E7EB;
}

.modal-footer .btn-primary {
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    border: none;
    color: white;
}

.modal-footer .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
}

/* Responsive */
@media (max-width: 1024px) {
    .plans-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .plan-card.featured {
        order: -1;
    }
}

@media (max-width: 768px) {
    .investment-summary,
    .returns-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .modal-header,
    .modal-body,
    .modal-footer {
        padding: 1.5rem;
    }
}
/* =====================================================
   COMPLETE ADMIN PANEL STYLES
   Add this entire file to layouts/styles.blade.php
   ===================================================== */

/* Admin Dashboard Base */
.admin-dashboard {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
    background: #F8FAFC;
    min-height: 100vh;
}

/* Admin Header */
.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2.5rem;
}

.admin-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
    margin-bottom: 0.5rem;
}

.admin-subtitle {
    color: #64748B;
    font-size: 1rem;
}

.admin-actions {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.btn-view-investor {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
}

.btn-view-investor:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
}

.btn-back-admin {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: white;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    color: #1E3A8A;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-back-admin:hover {
    border-color: #2563EB;
    background: #F3F4F6;
}

/* Stats Cards */
.admin-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.admin-stat-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    transition: all 0.3s ease;
}

.admin-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.admin-stat-card.green-gradient {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
}

.admin-stat-card.gold-gradient {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: white;
}

.admin-stat-card.orange-gradient {
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
    color: white;
}

.admin-stat-card.blue-gradient {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
    color: white;
}

.stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-label {
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    font-family: 'Crimson Pro', serif;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-change {
    font-size: 0.875rem;
    opacity: 0.8;
}

.stat-change.positive {
    opacity: 1;
}

.stat-link {
    color: inherit;
    text-decoration: underline;
    font-weight: 600;
    font-size: 0.875rem;
}

/* Content Grid */
.admin-content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}

/* Admin Cards */
.admin-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid #E5E7EB;
}

.admin-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.admin-card-header h3 {
    font-weight: 700;
    color: #1E3A8A;
    font-size: 1.25rem;
}

.view-all-link {
    color: #2563EB;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
}

.view-all-link:hover {
    text-decoration: underline;
}

/* Tables */
.admin-table-container {
    overflow-x: auto;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
}

.admin-table thead tr {
    background: #F8FAFC;
    border-bottom: 2px solid #E5E7EB;
}

.admin-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #64748B;
    font-size: 0.875rem;
    white-space: nowrap;
}

.admin-table td {
    padding: 1rem;
    border-bottom: 1px solid #F3F4F6;
    vertical-align: middle;
}

.admin-table tbody tr:hover {
    background: #F8FAFC;
}

/* User Cell */
.user-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

.user-name {
    font-weight: 600;
    color: #1E3A8A;
    display: block;
}

.user-email {
    font-size: 0.875rem;
    color: #64748B;
    display: block;
}

.verified-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    background: #D1FAE5;
    color: #065F46;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 0.5rem;
}

/* Table Cells */
.id-cell {
    color: #64748B;
    font-weight: 600;
}

.email-cell {
    font-size: 0.9rem;
    color: #64748B;
}

.phone-cell {
    font-size: 0.9rem;
    color: #64748B;
}

.balance-cell {
    font-weight: 700;
    color: #10B981;
}

.invested-cell {
    font-weight: 600;
    color: #2563EB;
}

.date-cell {
    font-size: 0.875rem;
    color: #64748B;
}

.amount-cell-large {
    font-size: 1.1rem;
}

.date-cell-detailed {
    font-size: 0.875rem;
}

.date-cell-detailed small {
    color: #9CA3AF;
    display: block;
}

/* Status Badges */
.status-badge {
    padding: 0.375rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.suspended {
    background: #FEE2E2;
    color: #991B1B;
}

.status-badge.pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-badge.completed {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.failed {
    background: #FEE2E2;
    color: #991B1B;
}

.status-badge-large {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 600;
}

/* Plan Badges */
.plan-badge {
    padding: 0.375rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.plan-badge.starter {
    background: #DBEAFE;
    color: #1E3A8A;
}

.plan-badge.professional {
    background: #D1FAE5;
    color: #065F46;
}

.plan-badge.elite {
    background: #FEF3C7;
    color: #92400E;
}

.plan-badge.none {
    background: #F3F4F6;
    color: #6B7280;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-action {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.btn-action.view {
    background: #DBEAFE;
    color: #2563EB;
}

.btn-action.edit {
    background: #DBEAFE;
    color: #2563EB;
}

.btn-action.balance {
    background: #FEF3C7;
    color: #F59E0B;
}

.btn-action.suspend {
    background: #FEE2E2;
    color: #EF4444;
}

.btn-action.activate {
    background: #D1FAE5;
    color: #10B981;
}

.btn-action.delete {
    background: #FEE2E2;
    color: #EF4444;
}

.btn-action.deactivate {
    background: #FEE2E2;
    color: #EF4444;
}

.btn-action:hover {
    transform: scale(1.1);
}

/* Sidebar */
.admin-sidebar {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

/* Quick Actions */
.quick-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.quick-action-btn.primary {
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
}

.quick-action-btn.secondary {
    background: #F3F4F6;
    color: #1E3A8A;
    border: 2px solid #E5E7EB;
}

.quick-action-btn.warning {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: white;
}

.quick-action-btn:hover {
    transform: translateX(4px);
}

/* System Status */
.system-status {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.status-label {
    color: #64748B;
    font-size: 0.95rem;
}

.status-indicator {
    font-weight: 600;
}

.status-indicator.online {
    color: #10B981;
}

.status-value {
    color: #1E3A8A;
    font-weight: 600;
    font-size: 0.95rem;
}

/* Quick Stats */
.quick-stats {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.quick-stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.quick-stat-label {
    color: #64748B;
    font-size: 0.95rem;
}

.quick-stat-value {
    color: #1E3A8A;
    font-weight: 700;
    font-size: 1.1rem;
}

/* Stats Quick (in header) */
.stats-quick {
    display: flex;
    gap: 1.5rem;
}

.stat-quick-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 0.75rem 1.5rem;
    background: white;
    border-radius: 10px;
    border: 2px solid #E5E7EB;
}

.stat-quick-item.pending {
    border-color: #F59E0B;
    background: #FEF3C7;
}

.stat-quick-item.approved {
    border-color: #10B981;
    background: #D1FAE5;
}

.stat-quick-label {
    font-size: 0.75rem;
    color: #64748B;
    font-weight: 600;
}

.stat-quick-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1E3A8A;
}

/* Filters */
.filters-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr auto;
    gap: 1rem;
    align-items: center;
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.search-box svg {
    position: absolute;
    left: 1rem;
    color: #64748B;
}

.search-box input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 3rem;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    font-size: 0.95rem;
}

.search-box input:focus {
    outline: none;
    border-color: #2563EB;
}

.filter-select {
    padding: 0.75rem 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    background: white;
    font-size: 0.95rem;
    cursor: pointer;
}

.filter-select:focus {
    outline: none;
    border-color: #2563EB;
}

.btn-add-user,
.btn-add-task {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.btn-add-user:hover,
.btn-add-task:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #9CA3AF;
}

.empty-state-tasks {
    grid-column: 1 / -1;
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-tasks svg {
    margin: 0 auto 1.5rem;
}

.empty-state-tasks h3 {
    color: #1E3A8A;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.empty-state-tasks p {
    color: #64748B;
    margin-bottom: 2rem;
}

.btn-create-first {
    padding: 1rem 2rem;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

/* Task Cards */
.tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
}

.task-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    border: 2px solid #E5E7EB;
    transition: all 0.3s ease;
}

.task-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.task-card.active {
    border-color: #10B981;
    background: linear-gradient(135deg, #F0FDF4 0%, #ffffff 100%);
}

.task-card.inactive {
    border-color: #E5E7EB;
    opacity: 0.7;
}

.task-card-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.task-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0;
}

.task-status-badge {
    padding: 0.375rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.task-status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.task-status-badge.inactive {
    background: #F3F4F6;
    color: #6B7280;
}

.task-description {
    color: #64748B;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.task-meta {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

.task-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #64748B;
    font-size: 0.95rem;
}

.task-meta-item strong {
    color: #1E3A8A;
}

.task-actions {
    display: flex;
    gap: 0.5rem;
    padding-top: 1rem;
    border-top: 1px solid #F3F4F6;
}

.btn-task-action {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 2px solid;
    border-radius: 8px;
    background: transparent;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-task-action.edit {
    border-color: #2563EB;
    color: #2563EB;
}

.btn-task-action.edit:hover {
    background: #2563EB;
    color: white;
}

.btn-task-action.activate {
    border-color: #10B981;
    color: #10B981;
}

.btn-task-action.activate:hover {
    background: #10B981;
    color: white;
}

.btn-task-action.deactivate {
    border-color: #EF4444;
    color: #EF4444;
}

.btn-task-action.deactivate:hover {
    background: #EF4444;
    color: white;
}

.btn-task-action.delete {
    border-color: #EF4444;
    color: #EF4444;
}

.btn-task-action.delete:hover {
    background: #EF4444;
    color: white;
}

/* Withdrawal Actions */
.action-buttons-withdrawal {
    display: flex;
    gap: 0.5rem;
}

.btn-withdrawal {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border: 2px solid;
    border-radius: 8px;
    background: transparent;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-withdrawal.approve {
    border-color: #10B981;
    color: #10B981;
}

.btn-withdrawal.approve:hover {
    background: #10B981;
    color: white;
}

.btn-withdrawal.decline {
    border-color: #EF4444;
    color: #EF4444;
}

.btn-withdrawal.decline:hover {
    background: #EF4444;
    color: white;
}

.processed-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.processed-info small {
    color: #64748B;
    font-size: 0.875rem;
}

.btn-view-note {
    padding: 0.375rem 0.75rem;
    background: #F3F4F6;
    border: 1px solid #E5E7EB;
    border-radius: 6px;
    color: #2563EB;
    font-size: 0.875rem;
    cursor: pointer;
}

.btn-view-note:hover {
    background: #E5E7EB;
}

/* Method Badge */
.method-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #F8FAFC;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.875rem;
}

.crypto-icon {
    font-size: 1.25rem;
}

.bank-icon {
    font-size: 1.25rem;
}

/* Modals */
.admin-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}

.admin-modal.active {
    display: flex;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2rem;
    border-bottom: 2px solid #F3F4F6;
}

.modal-header h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1E3A8A;
    font-family: 'Crimson Pro', serif;
    margin: 0;
}

.modal-close {
    width: 36px;
    height: 36px;
    background: #F3F4F6;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748B;
    font-size: 1.5rem;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: #E5E7EB;
    color: #EF4444;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    display: flex;
    gap: 1rem;
    padding: 2rem;
    border-top: 2px solid #F3F4F6;
}

/* Form Elements */
.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #1E3A8A;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.form-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #E5E7EB;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}

.form-input:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}

textarea.form-input {
    resize: vertical;
    font-family: inherit;
}

.form-hint {
    display: block;
    color: #64748B;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.checkbox-label-block {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    background: #F8FAFC;
    border-radius: 10px;
    cursor: pointer;
}

.checkbox-label-block input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    margin-top: 0.125rem;
}

/* Radio Groups */
.radio-group-inline {
    display: flex;
    gap: 1.5rem;
}

.radio-label-inline {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.radio-label-inline input[type="radio"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

/* Buttons */
.btn-secondary,
.btn-primary,
.btn-danger {
    flex: 1;
    padding: 0.875rem 1.5rem;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    font-size: 1rem;
}

.btn-secondary {
    background: #F3F4F6;
    border: 2px solid #E5E7EB;
    color: #64748B;
}

.btn-secondary:hover {
    background: #E5E7EB;
}

.btn-primary {
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
}

.btn-danger {
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
    color: white;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
}

/* Warning Box */
.warning-box {
    display: flex;
    align-items: start;
    gap: 1rem;
    padding: 1.25rem;
    background: #FEE2E2;
    border-radius: 10px;
    border: 2px solid #EF4444;
    margin-bottom: 1.5rem;
}

.warning-box svg {
    flex-shrink: 0;
}

.warning-box strong {
    color: #991B1B;
}

.warning-box div {
    color: #7F1D1D;
    line-height: 1.6;
}

/* Notifications Page Specific */
.notifications-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

.notification-form-card {
    grid-column: span 2;
}

.card-section-title {
    font-weight: 700;
    color: #1E3A8A;
    font-size: 1.25rem;
    margin-bottom: 1.5rem;
}

.char-counter {
    text-align: right;
    font-size: 0.875rem;
    color: #64748B;
    margin-top: 0.5rem;
}

.notification-preview {
    margin-top: 2rem;
    padding: 1.5rem;
    background: #F8FAFC;
    border-radius: 12px;
}

.notification-preview h4 {
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 1rem;
}

.preview-notification {
    display: flex;
    align-items: start;
    gap: 1rem;
    padding: 1.25rem;
    background: white;
    border-radius: 10px;
    border: 2px solid #E5E7EB;
}

.preview-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.preview-content {
    flex: 1;
}

.preview-title {
    font-weight: 700;
    color: #1E3A8A;
    margin-bottom: 0.5rem;
}

.preview-message {
    color: #64748B;
    line-height: 1.6;
}

.btn-send-notification {
    width: 100%;
    padding: 1.25rem;
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 2rem;
    transition: all 0.2s ease;
}

.btn-send-notification:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
}

/* Notification History */
.notification-history {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.history-item {
    padding: 1.25rem;
    background: #F8FAFC;
    border-radius: 10px;
    border: 1px solid #E5E7EB;
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.history-header strong {
    color: #1E3A8A;
    font-weight: 700;
}

.history-time {
    color: #64748B;
    font-size: 0.875rem;
}

.history-message {
    color: #64748B;
    margin-bottom: 0.75rem;
    line-height: 1.6;
}

.history-meta {
    display: flex;
    gap: 1rem;
}

.meta-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    background: white;
    border-radius: 8px;
    font-size: 0.875rem;
    color: #64748B;
}

.empty-history {
    padding: 3rem 1rem;
    text-align: center;
    color: #9CA3AF;
}

/* Templates Help */
.templates-help {
    grid-column: span 2;
}

.templates-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}

.template-item {
    padding: 1.5rem;
    background: #F8FAFC;
    border: 2px solid #E5E7EB;
    border-radius: 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.template-item:hover {
    border-color: #2563EB;
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
}

.template-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
}

.template-item strong {
    display: block;
    color: #1E3A8A;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.template-item p {
    color: #64748B;
    font-size: 0.875rem;
    margin: 0;
}

/* Pagination */
.pagination-wrapper {
    display: flex;
    justify-content: center;
    padding: 2rem 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .admin-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .admin-content-grid {
        grid-template-columns: 1fr;
    }
    
    .notifications-grid {
        grid-template-columns: 1fr;
    }
    
    .notification-form-card,
    .templates-help {
        grid-column: span 1;
    }
}

@media (max-width: 768px) {
    .admin-dashboard {
        padding: 1rem;
    }
    
    .admin-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .admin-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .tasks-grid {
        grid-template-columns: 1fr;
    }
    
    .templates-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}
/* Responsive Design */
@media (max-width: 768px) {
    .registration-wrapper {
        padding: 1rem 0.5rem;
    }

    .registration-card {
        padding: 2rem 1.5rem;
    }

    .registration-title {
        font-size: 1.5rem;
    }

    .progress-step {
        flex-direction: column;
    }

    .step-circle {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }

    .step-label {
        font-size: 0.75rem;
    }

    .progress-line {
        top: 20px;
    }

    .form-row {
        flex-direction: column;
        gap: 1rem;
    }

    .btn-large {
        font-size: 1rem;
        padding: 1rem 1.5rem;
    }
}

@media (max-width: 480px) {
    .step-label {
        display: none;
    }

    .registration-card {
        padding: 1.5rem 1rem;
    }
 
}

</style>