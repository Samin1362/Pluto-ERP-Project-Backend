<?php

namespace Tests\Feature\Parties;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Mail\PaymentReminderMail;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Payment reminders to customers (PEP-516): by hand, at most one a week, logged; the daily job for companies that switch it on. */
class PaymentReminderTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $trip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        $cash = app(MoneyAccountService::class)->create(['account_type' => 'cash']);
        // 10,000 due 40 days ago, 5,000 due 10 days ago, 3,000 not due; a 4,000 receipt settles the oldest first → 11,000 overdue on 2 invoices.
        $this->invoice('10000', -40);
        $this->invoice('5000', -10);
        $this->invoice('3000', 20);
        $this->req('POST', '/api/v1/customer-receipts', ['party_id' => $this->karim->id, 'date' => $this->today(), 'amount' => '4000.00',
            'money_account_id' => $cash->id, 'method' => 'cash', 'auto_allocate' => true])->assertCreated();
        Mail::fake();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function invoice(string $amount, int $dueInDays, ?Party $to = null): SalesInvoice
    {
        $this->useBooks();
        $inv = app(SalesInvoiceService::class)->createDraft(['party_id' => ($to ?? $this->karim)->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'due_date' => CarbonImmutable::parse($this->today())->addDays(max($dueInDays, 0))->toDateString(),
            'lines' => [['product_id' => $this->trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]);
        $inv = app(SalesInvoicePostingService::class)->post($inv);
        if ($dueInDays < 0) {
            DB::table('sales_invoices')->where('id', $inv->id)->update(['invoice_date' => CarbonImmutable::parse($this->today())->addDays($dueInDays - 1)->toDateString(),
                'due_date' => CarbonImmutable::parse($this->today())->addDays($dueInDays)->toDateString()]);
        }

        return $inv->refresh();
    }

    public function test_a_reminder_lists_the_overdue_invoices_attaches_the_statement_and_is_logged(): void
    {
        $url = "/api/v1/parties/{$this->karim->id}/payment-reminder";
        $this->assertProblem($this->req('POST', $url), 422, 'party_has_no_email');
        $this->karim->update(['email' => 'accounts@karim.example']);

        $r = $this->req('POST', $url)->assertCreated();
        $r->assertJsonPath('data.trigger', 'manual')->assertJsonPath('data.email', 'accounts@karim.example')->assertJsonPath('data.invoices_count', 2)
            ->assertJsonPath('data.overdue_amount', '11000.00')->assertJsonPath('data.sent_by.name', 'Owner');
        Mail::assertQueued(PaymentReminderMail::class, function (PaymentReminderMail $m) {
            $text = view('mail.payment-reminder', ['partyName' => $m->partyName, 'companyName' => $m->companyName, 'invoices' => $m->invoices, 'totalDue' => $m->totalDue])->render();

            return $m->hasTo('accounts@karim.example') && str_starts_with(base64_decode($m->pdf), '%PDF') && count($m->invoices) === 2
                && $m->invoices[0]['days_late'] === 40 && $m->invoices[0]['balance_due'] === '6000.00'
                && str_contains($text, 'Total overdue: Tk 11,000.00') && str_contains($text, '(40 days late)');
        });

        // At most one a week, whichever way it was sent.
        $this->assertProblem($this->req('POST', $url), 409, 'reminder_sent_recently');
        Mail::assertQueuedCount(1);
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/payment-reminders")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.overdue_amount', '11000.00');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'payment_reminder_sent')->exists());

        DB::table('payment_reminders')->update(['sent_at' => now()->subDays(8)]);
        $this->req('POST', $url)->assertCreated();
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/payment-reminders")->assertJsonPath('meta.total', 2);
    }

    public function test_nothing_to_remind_and_suppliers_are_refused(): void
    {
        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $rahman->update(['email' => 'rahman@shop.example']);
        $this->invoice('3000', 20, $rahman);
        $this->assertProblem($this->req('POST', "/api/v1/parties/{$rahman->id}/payment-reminder"), 422, 'no_overdue_invoices');
        $this->assertProblem($this->req('POST', "/api/v1/parties/{$this->supplier()->id}/payment-reminder"), 404, 'not_found');
        Mail::assertNothingQueued();
    }

    public function test_the_daily_job_reminds_customers_once_an_invoice_is_late_enough(): void
    {
        $this->karim->update(['email' => 'accounts@karim.example']);
        $this->artisan('pluto:reminders:send')->assertSuccessful();
        Mail::assertNothingQueued(); // automatic reminders are off by default

        $s = $this->req('GET', '/api/v1/company/settings')->assertOk();
        $s->assertJsonPath('data.reminders_auto', false)->assertJsonPath('data.reminder_days_after_due', 7);
        $this->api('PATCH', '/api/v1/company/settings', $this->token, $this->books->id, ['reminders_auto' => true, 'reminder_days_after_due' => 60],
            ['If-Match' => 'W/"'.$s->json('data.version').'"'])->assertOk()->assertJsonPath('data.reminders_auto', true);
        $this->useBooks();
        $this->artisan('pluto:reminders:send')->assertSuccessful();
        Mail::assertNothingQueued(); // the oldest is 40 days late, under 60

        DB::table('company_settings')->where('company_id', $this->books->id)->update(['reminder_days_after_due' => 30]);
        $this->artisan('pluto:reminders:send')->assertSuccessful();
        Mail::assertQueued(PaymentReminderMail::class, fn (PaymentReminderMail $m) => $m->hasTo('accounts@karim.example') && count($m->invoices) === 2);
        $this->assertSame(['auto', null], [DB::table('payment_reminders')->value('trigger'), DB::table('payment_reminders')->value('sent_by')]);

        $this->artisan('pluto:reminders:send')->assertSuccessful(); // not again within the week
        Mail::assertQueuedCount(1);
        $this->assertProblem($this->api('PATCH', '/api/v1/company/settings', $this->token, $this->books->id, ['reminder_days_after_due' => 0],
            ['If-Match' => 'W/"'.$this->req('GET', '/api/v1/company/settings')->json('data.version').'"']), 422, 'validation_failed');
        $this->useBooks();
    }

    public function test_permissions_and_isolation(): void
    {
        $this->karim->update(['email' => 'accounts@karim.example']);
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('POST', "/api/v1/parties/{$this->karim->id}/payment-reminder", token: $store), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$this->karim->id}/payment-reminders", token: $store), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('POST', "/api/v1/parties/{$this->karim->id}/payment-reminder", $ot, $other->id, [], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$this->karim->id}/payment-reminders", $ot, $other->id), 404, 'not_found');
        $this->useBooks();
        Mail::assertNothingQueued();
    }
}
