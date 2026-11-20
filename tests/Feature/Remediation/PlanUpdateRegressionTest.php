<?php
// LOCATION: tests/Feature/Remediation/PlanUpdateRegressionTest.php   (NEW — 19 Nov)
// Regression: the Active/Inactive toggle (partial update) must not un-feature a plan. WRITTEN, NOT EXECUTED.
namespace Tests\Feature\Remediation;


use App\Models\InvestmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rank\FinancialTestCase;

class PlanUpdateRegressionTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_partial_status_update_keeps_is_featured(): void
    {
        $plan = InvestmentPlan::find($this->plan());
        $plan->forceFill(['is_featured' => true])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/investment-plans/{$plan->id}", ['status' => 'inactive'])->assertOk();
        $plan->refresh();
        $this->assertSame('inactive', $plan->status);
        $this->assertTrue((bool) $plan->is_featured);
    }

    public function test_explicit_is_featured_false_still_unfeatures(): void
    {
        $plan = InvestmentPlan::find($this->plan());
        $plan->forceFill(['is_featured' => true])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/investment-plans/{$plan->id}", ['is_featured' => false])->assertOk();
        $this->assertFalse((bool) $plan->fresh()->is_featured);
    }
}
