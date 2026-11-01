<?php

namespace Tests\Feature\Platform;

use App\Domain\Approvals\NotificationService;
use App\Mail\NotificationMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Scheduled alerts and notification channels (11 §6; FR-013; K12; DEC-13; backlog P14.1-BE-04): pluto:alerts:run tells
 * sales.view holders about invoices that fell overdue and purchases.view holders about bills due in 3 days, once per
 * document, in-app unless switched off and by e-mail when switched on; --summary e-mails the daily summary.
 */
class AlertsTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private array $invoice;

    private array $bill;

    private CarbonImmutable $today;

    private CarbonImmutable $runOn;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->bill = $this->postDoc('purchase-bills', ['party_id' => $this->supplier()->id, 'supplier_bill_no' => 'BEO-1', 'bill_date' => $this->today->toDateString(),
            'warehouse_id' => $wh->id, 'lines' => [['product_id' => $oil->id, 'quantity' => '100', 'unit_cost' => '800.00']]]);
        $this->invoice = $this->postDoc('sales-invoices', ['party_id' => $this->customer()->id, 'invoice_date' => $this->today->toDateString(), 'warehouse_id' => $wh->id,
            'due_date' => $this->today->toDateString(), 'lines' => [['product_id' => $oil->id, 'quantity' => '20']]]);
        // The alerts run tomorrow: the invoice (due today) has just fallen overdue, the bill is due 3 days later.
        $this->runOn = $this->today->addDay();
        DB::table('purchase_bills')->where('id', $this->bill['id'])->update(['due_date' => $this->runOn->addDays(3)->toDateString()]);
        $this->userWithRole('sales');
        $this->userWithRole('store');
    }

    private function postDoc(string $resource, array $body): array
    {
        $h = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $d = $this->api('POST', "/api/v1/{$resource}", $this->token, $this->books->id, $body, $h())->assertCreated()->json('data');
        $posted = $this->api('POST', "/api/v1/{$resource}/{$d['id']}/post", $this->token, $this->books->id, [], $h())->assertOk()->json('data');
        $this->useBooks();

        return $posted;
    }

    private function userId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function pref(string $email, string $event, bool $inApp, bool $mail): void
    {
        DB::table('notification_preferences')->insert(['company_id' => $this->books->id, 'user_id' => $this->userId($email), 'event_key' => $event,
            'in_app' => $inApp, 'email' => $mail, 'sms' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<string> e-mails of who got the event in-app */
    private function told(string $event): array
    {
        return DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.company_id', $this->books->id)->where('n.event_key', $event)
            ->orderBy('u.email')->pluck('u.email')->all();
    }

    public function test_overdue_invoices_and_bills_due_soon_alert_the_right_people_once(): void
    {
        $this->pref('sales@meghna.example', 'invoice_overdue', false, true);
        $run = ['--company' => $this->books->id, '--date' => $this->runOn->toDateString()];
        $this->artisan('pluto:alerts:run', $run)->expectsOutputToContain('1 overdue invoice(s), 1 bill(s) due in 3 days')->assertSuccessful();

        $this->assertSame(['owner@meghna.example'], $this->told('invoice_overdue'), 'Sales staff switched in-app off');
        $this->assertSame(['owner@meghna.example', 'store@meghna.example'], $this->told('bill_due_soon'), 'purchases.view holders');
        $n = DB::table('notifications')->where('event_key', 'invoice_overdue')->first();
        $this->assertSame(["{$this->invoice['number']} is overdue", 'alerts', 'sales_invoices', $this->invoice['id']], [$n->title, $n->category, $n->link_type, (int) $n->link_id]);
        $this->assertStringContainsString('৳ 18,800.00 to collect', $n->body);
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('sales@meghna.example') && $m->subjectLine === "{$this->invoice['number']} is overdue"
            && str_ends_with((string) $m->link, "/sales/invoices/{$this->invoice['id']}"));
        Mail::assertQueuedCount(1);

        $owner = $this->api('GET', '/api/v1/notifications?category=alerts', $this->token, $this->books->id)->assertOk();
        $this->assertSame(2, $owner->json('meta.total'));

        $this->artisan('pluto:alerts:run', $run)->expectsOutputToContain('0 overdue invoice(s), 0 bill(s) due in 3 days');
        $this->assertCount(1, $this->told('invoice_overdue'), 'once per document');
        $this->artisan('pluto:alerts:run', ['--company' => $this->books->id, '--date' => $this->runOn->addDay()->toDateString()])
            ->expectsOutputToContain('0 overdue invoice(s)');
    }

    public function test_the_daily_summary_goes_to_those_who_asked_with_only_what_their_role_may_see(): void
    {
        $this->pref('owner@meghna.example', 'daily_summary', true, true);
        $this->pref('sales@meghna.example', 'daily_summary', true, true);
        $this->artisan('pluto:alerts:run', ['--company' => $this->books->id, '--summary' => true, '--date' => $this->runOn->toDateString()])
            ->expectsOutputToContain('2 summary e-mails')->assertSuccessful();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(NotificationMail::class, function (NotificationMail $m) {
            $text = implode("\n", $m->lines);

            return $m->hasTo('owner@meghna.example') && str_contains($text, 'Sales yesterday: 1 invoice, ৳ 18,800.00.') && str_contains($text, 'Overdue invoices: 1, ৳ 18,800.00')
                && str_contains($text, 'Bills due this week: 1, ৳ 80,000.00') && str_contains($text, 'reorder level');
        });
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('sales@meghna.example') && str_contains(implode("\n", $m->lines), 'Sales yesterday')
            && ! str_contains(implode("\n", $m->lines), 'Overdue') && ! str_contains(implode("\n", $m->lines), 'Cash'));
    }

    public function test_approval_notifications_follow_the_same_switches(): void
    {
        $this->useBooks();
        $accountant = $this->userWithRole('accountant');
        $this->pref('owner@meghna.example', 'approval_requested', false, true);
        app(NotificationService::class)->notify($this->books->id, [$this->userId('owner@meghna.example'), $accountant->id], 'approval_requested',
            'JV-0001 needs approval', null, 'approvals', 'approval_requests', 1, '/notifications?tab=approvals');
        $this->assertSame(['accountant@meghna.example'], $this->told('approval_requested'));
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('owner@meghna.example'));
    }
}
