@extends('layouts.app')

@section('title', 'Investment Plans')

@section('content')
<div class="dashboard-container">
    <!-- Header -->
    <div style="margin-bottom: 3rem;">
        <a href="{{ route('investor-investment.investments.index') }}" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #1E3A8A; font-weight: 600; text-decoration: none; margin-bottom: 1.5rem;">
            ← Back to Investments
        </a>
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #0F172A; font-family: 'Crimson Pro', serif; margin-bottom: 0.5rem;">
            Investment Plans
        </h1>
        <p style="color: #6B7280; font-size: 1.1rem;">
            Choose from our carefully curated investment opportunities
        </p>
    </div>

    <!-- Plans Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem; margin-bottom: 3rem;">
        @forelse($plans ?? [] as $plan)
            <div style="background: white; border: 2px solid #E5E7EB; border-radius: 12px; padding: 2rem; transition: all 0.3s ease; position: relative; overflow: hidden;"
                 onmouseover="this.style.transform='translateY(-8px)'; this.style.boxShadow='0 20px 40px rgba(0, 0, 0, 0.1)'; this.style.borderColor='#1E3A8A';"
                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'; this.style.borderColor='#E5E7EB';">

                <!-- Popular Badge -->
                @if($loop->first)
                    <div style="position: absolute; top: -12px; right: 20px; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); color: white; padding: 0.5rem 1.5rem; border-radius: 0 0 8px 8px; font-weight: 700; font-size: 0.85rem;">
                        ⭐ POPULAR
                    </div>
                @endif

                <!-- Plan Header -->
                <div style="margin-bottom: 2rem; margin-top: {{ $loop->first ? '1.5rem' : '0' }};">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h2 style="font-size: 1.75rem; font-weight: 700; color: #0F172A; margin: 0;">
                            {{ $plan->name }}
                        </h2>
                        <span style="display: inline-block; padding: 0.5rem 1rem; background: #EFF6FF; color: #1E3A8A; border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
                            {{ $plan->duration_months }} months
                        </span>
                    </div>
                    <p style="color: #6B7280; margin: 0;">{{ $plan->description ?? 'Premium investment opportunity' }}</p>
                </div>

                <!-- ROI Highlight -->
                <div style="background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%); padding: 1.5rem; border-radius: 10px; margin-bottom: 1.5rem; text-align: center; border-left: 4px solid #1E3A8A;">
                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Expected ROI</div>
                    <div style="font-size: 2.5rem; font-weight: 700; color: #10B981; font-family: 'Crimson Pro', serif;">
                        {{ $plan->profit_percentage }}%
                    </div>
                </div>

                <!-- Investment Range -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #E5E7EB;">
                    <div>
                        <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Min. Investment</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #1E3A8A;">${{ number_format($plan->min_amount, 0) }}</div>
                    </div>
                    <div>
                        <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Max. Investment</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #1E3A8A;">${{ number_format($plan->max_amount, 0) }}</div>
                    </div>
                </div>

                <!-- Features -->
                <div style="margin-bottom: 2rem;">
                    <div style="font-weight: 600; color: #0F172A; margin-bottom: 1rem;">What's Included:</div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem;">
                        <li style="display: flex; align-items: center; gap: 0.5rem; color: #6B7280;">
                            <span style="color: #10B981; font-weight: 700;">✓</span>
                            Professional management team
                        </li>
                        <li style="display: flex; align-items: center; gap: 0.5rem; color: #6B7280;">
                            <span style="color: #10B981; font-weight: 700;">✓</span>
                            Monthly performance reports
                        </li>
                        <li style="display: flex; align-items: center; gap: 0.5rem; color: #6B7280;">
                            <span style="color: #10B981; font-weight: 700;">✓</span>
                            24/7 customer support
                        </li>
                        <li style="display: flex; align-items: center; gap: 0.5rem; color: #6B7280;">
                            <span style="color: #10B981; font-weight: 700;">✓</span>
                            Insured investments
                        </li>
                    </ul>
                </div>

                <!-- Invest Button -->
                <button onclick="openInvestmentModal({{ $plan->id }}, '{{ $plan->name }}', {{ $plan->profit_percentage }}, {{ $plan->min_amount }}, {{ $plan->max_amount }})"
                        style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.2s ease;"
                        onmouseover="this.style.boxShadow='0 8px 20px rgba(30, 58, 138, 0.4)'; this.style.transform='translateY(-2px)';"
                        onmouseout="this.style.boxShadow='none'; this.style.transform='translateY(0)';">
                    💰 Start Investing
                </button>

                <!-- Status -->
                <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #E5E7EB;">
                    <span style="display: inline-block; padding: 0.375rem 0.875rem; background: #D1FAE5; color: #065F46; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
                        {{ $plan->getActiveInvestmentsCount() ?? 0 }} Active Investors
                    </span>
                </div>
            </div>
        @empty
            <div style="grid-column: 1 / -1; text-align: center; padding: 3rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📊</div>
                <h3 style="color: #0F172A;">No Plans Available</h3>
                <p style="color: #6B7280;">Check back soon for new investment opportunities</p>
            </div>
        @endforelse
    </div>

    <!-- Risk Disclosure -->
    <div style="background: #FEF3C7; border: 2px solid #F59E0B; border-radius: 12px; padding: 2rem;">
        <div style="display: flex; gap: 1rem; align-items: flex-start;">
            <div style="font-size: 2rem;">⚠️</div>
            <div>
                <h3 style="color: #92400E; margin-bottom: 0.5rem;">Important Risk Disclosure</h3>
                <p style="color: #78350F; margin: 0;">
                    All investments carry risk. Past performance does not guarantee future results. 
                    Please review the investment plan details carefully and only invest what you can afford to lose.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Investment Modal -->
<div id="investmentModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
        <!-- Modal Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #E5E7EB;">
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #0F172A; font-family: 'Crimson Pro', serif; margin: 0;">
                Start Investment
            </h2>
            <button onclick="closeInvestmentModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6B7280;">
                ✕
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 2rem;">
            <!-- Investment Summary -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem; padding: 1.5rem; background: #F9FAFB; border-radius: 10px;">
                <div style="text-align: center;">
                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Plan</div>
                    <div id="modalPlanName" style="font-weight: 700; color: #0F172A;"></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">ROI</div>
                    <div style="font-weight: 700; color: #10B981; font-size: 1.25rem;" id="modalROI"></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Duration</div>
                    <div style="font-weight: 700; color: #0F172A;" id="modalDuration"></div>
                </div>
            </div>

            <form id="investmentForm" action="{{ route('investor-investment.investments.store') }}" method="POST">
                @csrf
                <input type="hidden" id="planId" name="investment_plan_id">

                <!-- Amount Input -->
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #0F172A;">Investment Amount</label>
                    <input type="number" name="amount" id="investmentAmount" 
                           style="width: 100%; padding: 0.75rem 1rem; border: 2px solid #E5E7EB; border-radius: 8px; font-size: 0.95rem;"
                           placeholder="Enter amount"
                           oninput="calculateReturns()"
                           required>
                    <div style="font-size: 0.85rem; color: #6B7280; margin-top: 0.5rem;" id="amountHint"></div>
                </div>

                <!-- Expected Returns Preview -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; padding: 1.5rem; background: #EFF6FF; border-radius: 10px;">
                    <div>
                        <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Investment</div>
                        <div style="font-weight: 700; color: #1E3A8A; font-size: 1.25rem;">
                            $<span id="previewAmount">0.00</span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.85rem; color: #6B7280; margin-bottom: 0.5rem;">Expected Profit</div>
                        <div style="font-weight: 700; color: #10B981; font-size: 1.25rem;">
                            $<span id="previewProfit">0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Risk Agreement -->
                <div style="margin-bottom: 1.5rem; padding: 1rem; background: #FEF3C7; border-radius: 8px; border-left: 4px solid #F59E0B;">
                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                        <input type="checkbox" id="riskAgreement" style="margin-top: 0.25rem;" required>
                        <span style="font-size: 0.9rem; color: #78350F;">
                            I understand that this is an investment and not a guaranteed return. I have read and agree to the terms and conditions.
                        </span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; cursor: pointer;">
                    💰 Confirm Investment
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    let currentPlanROI = 0;
    let currentMinAmount = 0;
    let currentMaxAmount = 0;

    function openInvestmentModal(planId, planName, roi, minAmount, maxAmount) {
        currentPlanROI = roi;
        currentMinAmount = minAmount;
        currentMaxAmount = maxAmount;

        document.getElementById('planId').value = planId;
        document.getElementById('modalPlanName').textContent = planName;
        document.getElementById('modalROI').textContent = roi + '%';
        document.getElementById('modalDuration').textContent = document.querySelector(`[data-plan="${planId}"]`)?.textContent || '6 months';
        document.getElementById('amountHint').textContent = `Min: $${minAmount.toLocaleString()} | Max: $${maxAmount.toLocaleString()}`;
        document.getElementById('investmentModal').style.display = 'flex';
    }

    function closeInvestmentModal() {
        document.getElementById('investmentModal').style.display = 'none';
        document.getElementById('investmentForm').reset();
    }

    function calculateReturns() {
        const amount = parseFloat(document.getElementById('investmentAmount').value) || 0;
        const profit = amount * (currentPlanROI / 100);

        document.getElementById('previewAmount').textContent = amount.toFixed(2);
        document.getElementById('previewProfit').textContent = profit.toFixed(2);
    }

    // Close modal when clicking outside
    document.getElementById('investmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeInvestmentModal();
        }
    });
</script>

<style>
    .dashboard-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 1rem;
        }
    }
</style>
@endsection