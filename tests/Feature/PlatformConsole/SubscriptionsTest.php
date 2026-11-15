<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Subscription tracking (K17; PEP-503; owner request 15 Nov 2026): terms per company, payments that extend paid_until,
 * reminders 7 days before and on the due day, suspension grace_days after it, and a payment that reopens the company.
 */
class SubscriptionsTest extends TestCase
{
    use BuildsMasterData;

    private string $admin;

    private string $owner;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $token = $this->login('admin@pluto.example');
        $this->admin = app(JwtService::class)->issue(User::where('email', 'admin@pluto.example')->firstOrFail(), (int) $this->claims($token)['sid'], ['pwd', 'otp'])['access_token'];
        $this->owner = $this->login('owner@meghna.example');
        $this->today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function terms(array $body)
    {
        return $this->api('POST', "/api/v1/platform/companies/{$this->books->id}/billing", $this->admin, null, $body);
    }

    private function pay(array $body)
    {
        return $this->api('POST', "/api/v1/platform/companies/{$this->books->id}/payments", $this->admin, null,
            $body + ['paid_on' => $this->today, 'method' => 'bkash'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function billing(): array
    {
        return collect($this->api('GET', '/api/v1/platform/companies', $this->admin)->json('data'))->firstWhere('id', $this->books->id)['billing'];
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today)->addDays($offset)->toDateString();
    }

    public function test_a_company_starts_not_billed_and_terms_are_set_and_audited(): void
    {
        $this->assertSame(['price' => null, 'paid_until' => null, 'grace_days' => 7, 'status' => 'not_billed', 'days_left' => null], $this->billing());

        $this->terms(['price' => '3000', 'paid_until' => $this->day(30), 'grace_days' => 5])->assertOk()
            ->assertJsonPath('data.billing.price', '3000.00')->assertJsonPath('data.billing.status', 'paid')->assertJsonPath('data.billing.days_left', 30)
            ->assertJsonPath('data.billing.grace_days', 5);
        $this->terms(['paid_until' => $this->day(5)])->assertOk()->assertJsonPath('data.billing.status', 'due_soon');
        $this->terms(['paid_until' => $this->day(-2)])->assertOk()->assertJsonPath('data.billing.status', 'overdue')->assertJsonPath('data.billing.days_left', -2);
        $this->assertSame(3, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'billing_changed')->count());

        $this->assertProblem($this->terms(['price' => '30,00']), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', "/api/v1/platform/companies/{$this->books->id}/billing", $this->owner, null, ['price' => '1']), 403, 'platform_admin_only');
    }

    public function test_a_payment_extends_paid_until_from_the_later_date_and_tells_the_owners(): void
    {
        $this->terms(['price' => '3000', 'paid_until' => $this->day(10)])->assertOk();
        $p = $this->pay(['amount' => '9000', 'months' => 3, 'reference' => 'BK-77'])->assertCreated();
        $expected = CarbonImmutable::parse($this->day(10))->addMonthsNoOverflow(3)->toDateString();
        $p->assertJsonPath('data.amount', '9000.00')->assertJsonPath('data.months', 3)->assertJsonPath('data.paid_until', $expected)->assertJsonPath('data.method', 'bkash');
        $this->assertSame($expected, $this->billing()['paid_until']);

        // Paid after it ran out: counted from the payment day, not the old date.
        $this->terms(['paid_until' => $this->day(-3)])->assertOk();
        $this->pay(['amount' => '3000', 'months' => 1])->assertCreated()->assertJsonPath('data.paid_until', CarbonImmutable::parse($this->today)->addMonthsNoOverflow(1)->toDateString());

        $list = $this->api('GET', "/api/v1/platform/companies/{$this->books->id}/payments", $this->admin)->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame('admin@pluto.example', User::where('name', $list->json('data.0.recorded_by'))->value('email'));
        $n = DB::table('notifications')->where('company_id', $this->books->id)->where('event_key', 'subscription_paid')->first();
        $this->assertSame('Pluto payment received, thank you', $n->title);
        $this->assertSame(2, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'subscription_paid')->count());

        $this->assertProblem($this->pay(['amount' => '0', 'months' => 1]), 422, 'validation_failed');
        $this->assertProblem($this->pay(['amount' => '100', 'months' => 0]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/platform/companies/999999/payments', $this->admin, null,
            ['paid_on' => $this->today, 'amount' => '1', 'months' => 1, 'method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
    }

    public function test_the_daily_check_reminds_then_suspends_after_the_grace_days_and_a_payment_reopens(): void
    {
        Mail::fake();
        $this->terms(['price' => '3000', 'paid_until' => $this->day(7), 'grace_days' => 3])->assertOk();
        Artisan::call('pluto:subscriptions:check');
        $this->assertSame('Your Pluto subscription is due in 7 days', DB::table('notifications')->where('company_id', $this->books->id)->where('event_key', 'subscription_due')->value('title'));

        // Within the grace days: overdue, still open.
        $this->terms(['paid_until' => $this->day(-3)])->assertOk();
        Artisan::call('pluto:subscriptions:check');
        $this->assertSame('active', DB::table('companies')->where('id', $this->books->id)->value('status'));

        // Past them: suspended for payment, nobody can open it.
        $this->terms(['paid_until' => $this->day(-4)])->assertOk();
        Artisan::call('pluto:subscriptions:check');
        $this->assertSame(['suspended', 1], array_values((array) DB::table('companies')->where('id', $this->books->id)->first(['status', 'billing_suspended'])));
        $this->assertSame('suspended', $this->billing()['status']);
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->owner, $this->books->id), 403, 'not_a_member');
        $this->assertSame(1, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'suspended')->count());

        // A payment reopens it.
        $this->pay(['amount' => '3000', 'months' => 1])->assertCreated();
        $this->assertSame(['active', 0], array_values((array) DB::table('companies')->where('id', $this->books->id)->first(['status', 'billing_suspended'])));
        $this->api('GET', '/api/v1/parties?type=customer', $this->owner, $this->books->id)->assertOk();
        $this->assertStringContainsString('Your company is open again', DB::table('notifications')->where('event_key', 'subscription_paid')->value('body'));
    }

    public function test_a_company_that_is_not_billed_is_never_suspended(): void
    {
        Mail::fake();
        Artisan::call('pluto:subscriptions:check', ['--date' => CarbonImmutable::parse($this->today)->addYears(2)->toDateString()]);
        $this->assertSame('active', DB::table('companies')->where('id', $this->books->id)->value('status'));
        Mail::assertNothingSent();
    }
}
