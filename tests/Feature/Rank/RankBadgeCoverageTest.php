<?php
// LOCATION: tests/Feature/Rank/RankBadgeCoverageTest.php
//
// Acceptance criterion 3: the assigned badge is returned wherever an investor's NAME is displayed.
// Covers the screens that were missing it: Admin Investments, Admin KYC, Admin Dashboard recent
// lists, Admin + Financial Email Center search, Financial Transactions, Financial Investments and
// Financial Messages. Each endpoint must return `rank` (badge payload) for a ranked investor and an
// explicit `rank: null` for an unranked one, with permissions and existing response shapes intact.

namespace Tests\Feature\Rank;

use App\Models\Deposit;
use App\Models\Message;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

class RankBadgeCoverageTest extends RankTestCase
{
    protected User $ranked;
    protected User $unranked;
    protected User $financial;

    protected function setUp(): void
    {
        parent::setUp();

        // The admin dashboard aggregates with MySQL's MONTH(); give the sqlite test connection the same
        // function so the REAL endpoint runs (production code is untouched).
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('MONTH', fn ($d) => (int) date('n', strtotime((string) $d)), 1);
        }

        $this->financial = User::factory()->create(['role' => 'financial', 'status' => 'active']);

        $this->ranked   = $this->investor(['name' => 'Rita Ranked', 'id_document_path' => 'docs/rita.png', 'kyc_status' => 'pending', 'kyc_verified' => false]);
        $this->unranked = $this->investor(['name' => 'Uma Unranked', 'id_document_path' => 'docs/uma.png', 'kyc_status' => 'pending', 'kyc_verified' => false]);
        DB::table('users')->where('id', $this->ranked->id)->update(['investor_rank_id' => $this->rank('gold')->id]);

        foreach ([$this->ranked, $this->unranked] as $u) {
            $this->invest($u, 500);
            Deposit::create(['user_id' => $u->id, 'amount' => 100, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEP-' . uniqid(), 'status' => 'pending']);
            Withdrawal::create(['user_id' => $u->id, 'amount' => 50, 'method' => 'bank_transfer', 'account_details' => json_encode(['bank_name' => 'T']), 'status' => 'pending']);
            Message::create(['sender_id' => $u->id, 'receiver_id' => $this->financial->id, 'investor_id' => $u->id, 'subject' => 'Hi', 'body' => 'Question',
                'initiated_by' => 'investor', 'department' => 'financial', 'read_by_admin' => true, 'read_by_investor' => true, 'read_by_financial' => false]);
        }
    }

    /** Every array in the response that identifies investor $id and carries a `rank` key. */
    private function ranksFor($response, int $id): array
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found, $id) {
            if (!is_array($node)) return;
            if (($node['id'] ?? null) === $id && array_key_exists('rank', $node)) $found[] = $node['rank'];
            foreach ($node as $child) $walk($child);
        };
        $walk($response->json());
        return $found;
    }

    private function assertBadges($response, string $screen): void
    {
        $response->assertOk();
        $ranked = $this->ranksFor($response, $this->ranked->id);
        $none   = $this->ranksFor($response, $this->unranked->id);

        $this->assertNotEmpty($ranked, "$screen: ranked investor has no `rank` field in the response");
        $this->assertSame('gold', $ranked[0]['key'] ?? null, "$screen: wrong badge");
        $this->assertSame('Gold', $ranked[0]['name'] ?? null);
        foreach (['color', 'icon', 'level'] as $k) $this->assertArrayHasKey($k, $ranked[0], "$screen: badge payload missing $k");

        $this->assertNotEmpty($none, "$screen: unranked investor must still carry an explicit `rank` key");
        $this->assertNull($none[0], "$screen: unranked investor must have rank = null");
    }

    public function test_admin_investments_return_rank_on_the_investor(): void
    {
        $this->assertBadges($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/investments'), 'Admin Investments');
    }

    public function test_admin_investments_search_and_status_filters_still_work_with_rank(): void
    {
        $r = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/investments?search=Rita&status=active')->assertOk();
        $this->assertNotEmpty($this->ranksFor($r, $this->ranked->id));
        $this->assertSame([], $this->ranksFor($r, $this->unranked->id));
    }

    public function test_admin_investment_detail_returns_rank(): void
    {
        $inv = DB::table('investment_accounts')->where('user_id', $this->ranked->id)->value('id');
        $r = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/investments/{$inv}")->assertOk();
        $this->assertSame('gold', $this->ranksFor($r, $this->ranked->id)[0]['key'] ?? null);
    }

    public function test_financial_investments_return_rank_through_the_same_endpoint(): void
    {
        $this->assertBadges($this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/investments'), 'Financial Investments');
    }

    public function test_admin_kyc_list_and_detail_return_rank(): void
    {
        $this->assertBadges($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/kyc'), 'Admin KYC list');
        $detail = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/kyc/{$this->ranked->id}")->assertOk();
        $this->assertSame('gold', $detail->json('submission.rank.key'));
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/kyc/{$this->unranked->id}")->assertOk()->assertJsonPath('submission.rank', null);
    }

    public function test_admin_dashboard_recent_lists_return_rank(): void
    {
        $r = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/dashboard')->assertOk();
        $this->assertBadges($r, 'Admin Dashboard');   // recent investors AND pending withdrawals both carry it
        $this->assertGreaterThanOrEqual(2, count($this->ranksFor($r, $this->ranked->id)), 'both recent lists should carry rank');
    }

    public function test_email_center_investor_search_returns_rank_for_admin_and_financial(): void
    {
        $this->assertBadges($this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/email-center/investors/search?q=ank'), 'Admin Email Center');
        $a = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/email-center/investors/search?q=Rita')->assertOk();
        $this->assertSame('gold', $this->ranksFor($a, $this->ranked->id)[0]['key'] ?? null);

        $f = $this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/email-center/investors/search?q=Rita')->assertOk();
        $this->assertSame('gold', $this->ranksFor($f, $this->ranked->id)[0]['key'] ?? null);
        $f2 = $this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/email-center/investors/search?q=Uma')->assertOk();
        $this->assertSame([null], $this->ranksFor($f2, $this->unranked->id));
    }

    public function test_financial_transactions_list_and_detail_return_rank(): void
    {
        $list = $this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/transactions')->assertOk();
        $this->assertBadges($list, 'Financial Transactions');

        $dep = Deposit::where('user_id', $this->ranked->id)->value('id');
        $detail = $this->actingAs($this->financial, 'sanctum')->getJson("/api/financial/transactions/deposit/{$dep}")->assertOk();
        $this->assertSame('gold', $detail->json('investor.rank.key'));
        $this->assertSame('gold', $detail->json('transaction.investor.rank.key'));
    }

    public function test_financial_messages_list_and_thread_return_rank(): void
    {
        $this->assertBadges($this->actingAs($this->financial, 'sanctum')->getJson('/api/financial/messages'), 'Financial Messages');
        $thread = $this->actingAs($this->financial, 'sanctum')->getJson("/api/financial/messages/{$this->ranked->id}")->assertOk();
        $this->assertSame('gold', $thread->json('investor.rank.key'));
        $this->actingAs($this->financial, 'sanctum')->getJson("/api/financial/messages/{$this->unranked->id}")->assertOk()->assertJsonPath('investor.rank', null);
    }

    public function test_rank_data_does_not_open_these_screens_to_other_roles(): void
    {
        $this->actingAs($this->ranked, 'sanctum')->getJson('/api/admin/investments')->assertForbidden();
        $this->actingAs($this->ranked, 'sanctum')->getJson('/api/admin/kyc')->assertForbidden();
        $this->actingAs($this->ranked, 'sanctum')->getJson('/api/financial/transactions')->assertForbidden();
        $this->actingAs($this->ranked, 'sanctum')->getJson('/api/financial/messages')->assertForbidden();
        $this->actingAs($this->financial, 'sanctum')->getJson('/api/admin/kyc')->assertForbidden();
    }

    public function test_listing_endpoints_do_not_issue_a_query_per_row_for_rank(): void
    {
        foreach (range(1, 6) as $i) {
            $u = $this->investor(['name' => "Bulk $i", 'id_document_path' => "d/$i.png"]);
            DB::table('users')->where('id', $u->id)->update(['investor_rank_id' => $this->rank('silver')->id]);
            $this->invest($u, 100);
        }

        foreach (['/api/admin/investments', '/api/admin/kyc'] as $url) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();
            $rankQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'investor_ranks'))->count();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(1, $rankQueries, "$url loads ranks with $rankQueries queries (N+1)");
        }
    }
}
