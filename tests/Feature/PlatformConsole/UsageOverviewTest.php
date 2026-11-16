<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Usage overview in the platform console (K17; PEP-512): sign-ins, active people, this month's work, quiet companies. */
class UsageOverviewTest extends TestCase
{
    use BuildsMasterData;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd.
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $t = $this->login('admin@pluto.example');
        $this->admin = app(JwtService::class)->issue(User::where('email', 'admin@pluto.example')->firstOrFail(), (int) $this->claims($t)['sid'], ['pwd', 'otp'])['access_token'];
    }

    private function usage(int $companyId): array
    {
        return collect($this->api('GET', '/api/v1/platform/companies', $this->admin)->assertOk()->json('data'))->firstWhere('id', $companyId)['usage'];
    }

    public function test_a_working_company_shows_its_activity_and_is_not_quiet(): void
    {
        $owner = $this->login('owner@meghna.example');
        $this->useBooks();
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $post = function (string $res, array $body) use ($owner) {
            $d = $this->api('POST', "/api/v1/{$res}", $owner, $this->books->id, $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
            $this->useBooks();
            $this->api('POST', "/api/v1/{$res}/{$d['id']}/post", $owner, $this->books->id, [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
            $this->useBooks();
        };
        $post('purchase-bills', ['party_id' => $this->supplier()->id, 'supplier_bill_no' => 'B-1', 'bill_date' => $today, 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '10', 'unit_cost' => '800.00']]]);
        $post('sales-invoices', ['party_id' => $this->customer()->id, 'invoice_date' => $today, 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $oil->id, 'quantity' => '2']]]);

        $u = $this->usage($this->books->id);
        $this->assertSame([1, 1, false], [$u['invoices_this_month'], $u['bills_this_month'], $u['quiet']]);
        $this->assertGreaterThanOrEqual(1, $u['active_people_30d']);
        $this->assertNotNull($u['last_sign_in_at'], 'the owner signed in');
    }

    public function test_a_company_with_no_activity_for_a_week_is_quiet(): void
    {
        DB::table('audit_logs')->where('company_id', $this->books->id)->update(['created_at' => now()->subDays(10)]);
        $u = $this->usage($this->books->id);
        $this->assertTrue($u['quiet']);
        $this->assertSame([0, 0, 0, 0], [$u['active_people_30d'] > 0 ? 1 : 0, $u['invoices_this_month'], $u['bills_this_month'], $u['vouchers_this_month']]);
    }
}
