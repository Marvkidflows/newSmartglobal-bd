@extends('layouts.app')

@section('title', 'Investment Plans - Smart System')

@section('content')

<div class="modern-dashboard">
    <!-- Page Header -->
    <div class="dashboard-header-modern">
        <div>
            <h1 class="dashboard-title-modern">Investment Plans</h1>
            <p class="dashboard-subtitle-modern">Choose a plan that fits your investment goals</p>
        </div>
        <a href="{{ route('investor.dashboard') }}" class="btn-back">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M12 16L6 10L12 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Back to Dashboard
        </a>
    </div>

    <!-- Investment Plans Grid -->
    <div class="plans-grid-modern">
        <!-- Starter Plan -->
        <div class="plan-card">
            <div class="plan-header">
                <h3 class="plan-name">Starter Plan</h3>
                <div class="plan-badge">Low Risk</div>
            </div>
            <div class="plan-roi">
                <span class="roi-percentage">7%</span>
                <span class="roi-label">Monthly ROI</span>
            </div>
            <div class="plan-amount">
                <span class="amount-label">Minimum Investment</span>
                <h2 class="amount-value">$1,000</h2>
                <span class="amount-range">$1,000 - $4,999</span>
            </div>
            <div class="plan-duration">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/>
                    <path d="M10 5V10L13 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span>30 Days Duration</span>
            </div>
            <div class="plan-features">
                <h4>Features Included:</h4>
                <ul>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Daily profit distribution
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Basic daily tasks
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Email support
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Withdraw anytime
                    </li>
                </ul>
            </div>
            <button class="btn-invest" onclick="openInvestModal('Starter', 1000, 7)">
                Invest Now
            </button>
        </div>

        <!-- Professional Plan (Most Popular) -->
        <div class="plan-card featured">
            <div class="popular-badge">Most Popular</div>
            <div class="plan-header">
                <h3 class="plan-name">Professional Plan</h3>
                <div class="plan-badge">Medium Risk</div>
            </div>
            <div class="plan-roi">
                <span class="roi-percentage">12%</span>
                <span class="roi-label">Monthly ROI</span>
            </div>
            <div class="plan-amount">
                <span class="amount-label">Minimum Investment</span>
                <h2 class="amount-value">$5,000</h2>
                <span class="amount-range">$5,000 - $19,999</span>
            </div>
            <div class="plan-duration">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/>
                    <path d="M10 5V10L13 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span>30 Days Duration</span>
            </div>
            <div class="plan-features">
                <h4>Everything in Starter, plus:</h4>
                <ul>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Priority withdrawals
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Advanced tasks
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Priority support
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Monthly reports
                    </li>
                </ul>
            </div>
            <button class="btn-invest featured" onclick="openInvestModal('Professional', 5000, 12)">
                Invest Now
            </button>
        </div>

        <!-- Elite Plan -->
        <div class="plan-card">
            <div class="plan-header">
                <h3 class="plan-name">Elite Plan</h3>
                <div class="plan-badge">High Risk</div>
            </div>
            <div class="plan-roi">
                <span class="roi-percentage">20%</span>
                <span class="roi-label">Monthly ROI</span>
            </div>
            <div class="plan-amount">
                <span class="amount-label">Minimum Investment</span>
                <h2 class="amount-value">$20,000</h2>
                <span class="amount-range">$20,000+</span>
            </div>
            <div class="plan-duration">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/>
                    <path d="M10 5V10L13 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span>30 Days Duration</span>
            </div>
            <div class="plan-features">
                <h4>Everything in Professional, plus:</h4>
                <ul>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Instant withdrawals
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        VIP exclusive tasks
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Dedicated manager
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="#10B981" stroke-width="2"/>
                            <path d="M5 8L7 10L11 6" stroke="#10B981" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Weekly strategy calls
                    </li>
                </ul>
            </div>
            <button class="btn-invest" onclick="openInvestModal('Elite', 20000, 20)">
                Invest Now
            </button>
        </div>
    </div>

    <!-- Risk Disclosure Notice -->
    <div class="risk-disclosure-banner">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="#EF4444" stroke-width="2"/>
            <path d="M2 17L12 22L22 17M2 12L12 17L22 12" stroke="#EF4444" stroke-width="2"/>
        </svg>
        <div>
            <h4>Investment Risk Disclosure</h4>
            <p>All investments carry risk. Past performance does not guarantee future results. Your capital is at risk. ROI percentages are estimates and not guaranteed. Please invest responsibly and only invest what you can afford to lose.</p>
        </div>
    </div>
</div>

<!-- Investment Modal -->
<div class="invest-modal" id="investModal">
    <div class="modal-overlay" onclick="closeInvestModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Invest in Starter Plan</h3>
            <button class="modal-close" onclick="closeInvestModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        
        <form action="{{ route('investor.investments.create') }}" method="POST" id="investForm">
            @csrf
            <input type="hidden" name="plan" id="planInput">
            
            <div class="modal-body">
                <div class="investment-summary">
                    <div class="summary-item">
                        <span class="summary-label">Plan:</span>
                        <span class="summary-value" id="summaryPlan">-</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">ROI:</span>
                        <span class="summary-value roi-value" id="summaryROI">-</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Your Balance:</span>
                        <span class="summary-value">${{ number_format(Auth::user()->balance ?? 0, 2) }}</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Investment Amount (USD)</label>
                    <input 
                        type="number" 
                        name="amount" 
                        id="amountInput"
                        class="form-input-modern" 
                        min="1000"
                        max="{{ Auth::user()->balance ?? 0 }}"
                        step="100"
                        placeholder="Enter amount"
                        required
                        oninput="calculateReturns()"
                    >
                    <small class="form-hint" id="minAmountHint">Minimum: $1,000</small>
                </div>

                <div class="returns-preview">
                    <h4>Expected Returns</h4>
                    <div class="returns-grid">
                        <div class="return-item">
                            <span class="return-label">Investment</span>
                            <span class="return-value" id="investmentAmount">$0.00</span>
                        </div>
                        <div class="return-item">
                            <span class="return-label">ROI (30 days)</span>
                            <span class="return-value profit" id="roiAmount">$0.00</span>
                        </div>
                        <div class="return-item">
                            <span class="return-label">Total Return</span>
                            <span class="return-value total" id="totalReturn">$0.00</span>
                        </div>
                    </div>
                </div>

                <div class="risk-warning">
                    <input type="checkbox" id="riskAccept" required>
                    <label for="riskAccept">
                        I understand that all investments carry risk and returns are not guaranteed. I have read the investment terms and conditions.
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeInvestModal()">Cancel</button>
                <button type="submit" class="btn-primary">Confirm Investment</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
let currentROI = 0;

function openInvestModal(plan, minAmount, roi) {
    currentROI = roi;
    document.getElementById('investModal').classList.add('active');
    document.getElementById('modalTitle').textContent = `Invest in ${plan} Plan`;
    document.getElementById('planInput').value = plan;
    document.getElementById('summaryPlan').textContent = `${plan} Plan`;
    document.getElementById('summaryROI').textContent = `${roi}%`;
    document.getElementById('amountInput').min = minAmount;
    document.getElementById('minAmountHint').textContent = `Minimum: $${minAmount.toLocaleString()}`;
    document.getElementById('amountInput').value = minAmount;
    calculateReturns();
}

function closeInvestModal() {
    document.getElementById('investModal').classList.remove('active');
    document.getElementById('investForm').reset();
}

function calculateReturns() {
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const roi = currentROI / 100;
    const roiAmount = amount * roi;
    const total = amount + roiAmount;
    
    document.getElementById('investmentAmount').textContent = `$${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('roiAmount').textContent = `$${roiAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('totalReturn').textContent = `$${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}
</script>
@endpush
@endsection