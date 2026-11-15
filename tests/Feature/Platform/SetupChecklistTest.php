<?php

namespace Tests\Feature\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** New-company setup checklist (A1; PEP-511): steps done from the company's own data; an Owner hides it. */
class SetupChecklistTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd.
        $this->token = $this->login('owner@meghna.example');
    }

    private function checklist(?string $token = null)
    {
        $r = $this->api('GET', '/api/v1/setup-checklist', $token ?? $this->token, $this->books->id);
        $this->useBooks();

        return $r;
    }

    private function done(): array
    {
        return collect($this->checklist()->assertOk()->json('data.steps'))->filter(fn ($s) => $s['done'])->pluck('key')->values()->all();
    }

    public function test_a_new_company_starts_with_nothing_done_and_steps_tick_from_its_data(): void
    {
        $r = $this->checklist()->assertOk();
        $this->assertSame(['profile', 'logo', 'tax', 'money', 'warehouse', 'items', 'customers', 'first_invoice', 'team'], array_column($r->json('data.steps'), 'key'));
        $r->assertJsonPath('data.steps_count', 9)->assertJsonPath('data.hidden_at', null);
        $before = $r->json('data.done');

        DB::table('companies')->where('id', $this->books->id)->update(['registered_address' => 'Kawran Bazar, Dhaka']);
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->postDoc('purchase-bills', ['party_id' => $this->supplier()->id, 'supplier_bill_no' => 'B-1', 'bill_date' => $this->today(), 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '10', 'unit_cost' => '800.00']]]);
        $this->postDoc('sales-invoices', ['party_id' => $this->customer()->id, 'invoice_date' => $this->today(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $oil->id, 'quantity' => '2']]]);

        $done = $this->done();
        foreach (['profile', 'warehouse', 'items', 'customers', 'first_invoice'] as $k) {
            $this->assertContains($k, $done, $k);
        }
        $this->assertNotContains('logo', $done);
        $this->assertGreaterThan($before, $this->checklist()->json('data.done'));
    }

    public function test_an_owner_hides_it_and_a_viewer_reads_but_cannot_hide(): void
    {
        $this->userWithRole('viewer', 'viewer@meghna.example');
        $viewer = $this->login('viewer@meghna.example');
        $this->checklist($viewer)->assertOk();
        $this->assertProblem($this->api('POST', '/api/v1/setup-checklist/hide', $viewer, $this->books->id), 403, 'permission_denied');

        $r = $this->api('POST', '/api/v1/setup-checklist/hide', $this->token, $this->books->id)->assertOk();
        $this->assertNotNull($r->json('data.hidden_at'));
        $this->useBooks();
        $this->assertSame(1, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'setup_checklist_hidden')->count());
        $this->assertNotNull($this->checklist()->json('data.hidden_at'));
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function postDoc(string $resource, array $body): void
    {
        $d = $this->api('POST', "/api/v1/{$resource}", $this->token, $this->books->id, $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->useBooks();
        $this->api('POST', "/api/v1/{$resource}/{$d['id']}/post", $this->token, $this->books->id, [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->useBooks();
    }
}
