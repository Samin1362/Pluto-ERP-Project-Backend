<?php

namespace Tests\Feature\Platform;

use App\Models\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** GET /search (L1, L2; FR-012; BR-037, BR-052; TC-FE-005 backend side; backlog P14.1-BE-03). */
class SearchTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private array $invoice;

    private array $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $bengal = $this->supplier();
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->bill = $this->postDoc('purchase-bills', ['party_id' => $bengal->id, 'supplier_bill_no' => 'BEO-7781', 'bill_date' => $today, 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '100', 'unit_cost' => '800.00']]]);
        $this->invoice = $this->postDoc('sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $today, 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '20']]]);
    }

    private function req(string $uri, ?string $token = null)
    {
        $r = $this->api('GET', $uri, $token ?? $this->token, $this->books->id);
        $this->useBooks();

        return $r;
    }

    private function postDoc(string $resource, array $body): array
    {
        $h = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $d = $this->api('POST', "/api/v1/{$resource}", $this->token, $this->books->id, $body, $h())->assertCreated()->json('data');
        $posted = $this->api('POST', "/api/v1/{$resource}/{$d['id']}/post", $this->token, $this->books->id, [], $h())->assertOk()->json('data');
        $this->useBooks();

        return $posted;
    }

    /** @return array<string, array> groups by type */
    private function hits($r): array
    {
        return collect($r->json('data.groups'))->keyBy('type')->all();
    }

    public function test_numbers_amounts_and_names_find_their_records(): void
    {
        $number = $this->invoice['number'];
        $g = $this->hits($this->req('/api/v1/search?q='.urlencode($number))->assertOk()->assertJsonPath('data.query', $number));
        $this->assertSame([1, $number, "/sales/invoices/{$this->invoice['id']}"], [$g['sales_invoice']['count'], $g['sales_invoice']['items'][0]['title'], $g['sales_invoice']['items'][0]['url']]);
        $this->assertStringContainsString('Karim Distributors', $g['sales_invoice']['items'][0]['subtitle']);
        $this->assertStringContainsString('৳ 18,800.00', $g['sales_invoice']['items'][0]['subtitle']);
        $this->assertSame('Unpaid', $g['sales_invoice']['items'][0]['badge']);
        $this->assertSame(['party', 'sales_invoice', 'purchase_bill', 'voucher', 'ledger', 'product'], array_keys($g), 'every type the owner can view, even when empty');
        $this->assertSame(1, $g['voucher']['count'], "the invoice's journal carries the same number");

        $digits = substr($number, -4);
        $this->assertSame(1, $this->hits($this->req("/api/v1/search?q={$digits}&type=sales_invoice"))['sales_invoice']['count'], 'the number\'s last digits');
        foreach (['18800', '18,800.00', '৳ 18,800'] as $amount) {
            $this->assertSame(1, $this->hits($this->req('/api/v1/search?type=sales_invoice&q='.urlencode($amount)))['sales_invoice']['count'], $amount);
        }
        $this->assertSame(1, $this->hits($this->req('/api/v1/search?q=BEO-77&type=purchase_bill'))['purchase_bill']['count'], 'the supplier\'s bill number');

        $g = $this->hits($this->req('/api/v1/search?q=kar'));
        $this->assertSame(['Karim Distributors', 'Customer', "/customers/{$this->karim->id}"], [$g['party']['items'][0]['title'], $g['party']['items'][0]['badge'], $g['party']['items'][0]['url']]);
        $this->assertSame(1, $this->hits($this->req('/api/v1/search?q=dist&type=party'))['party']['count'], 'the start of any word');
        $this->assertSame(0, $this->hits($this->req('/api/v1/search?q=stri&type=party'))['party']['count'], 'not the middle of a word');
        $p = $this->hits($this->req('/api/v1/search?q=oil&type=product'))['product']['items'][0];
        $this->assertSame('Soybean oil 5L jar', $p['title']);
        $this->assertStringNotContainsString('800', $p['subtitle'], 'no cost (BR-052)');
        $this->assertSame('/accounting/ledgers/'.DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '6101')->value('id'),
            $this->hits($this->req('/api/v1/search?q=6101&type=ledger'))['ledger']['items'][0]['url']);

        $this->assertSame([2, 1], [count($this->req('/api/v1/search?q=Karim')->json('data.groups')) > 0 ? 2 : 0, count($this->req('/api/v1/search?q=Karim&type=party')->json('data.groups'))]);
        $this->assertProblem($this->req('/api/v1/search?q=k'), 422, 'validation_failed')->assertJsonPath('errors.q.0', 'Type at least 2 characters');
        $this->assertProblem($this->req('/api/v1/search'), 422, 'validation_failed');
        $this->assertProblem($this->req('/api/v1/search?q=ka&limit=50'), 422, 'validation_failed');
        $this->assertProblem($this->req('/api/v1/search?q=ka&type=user'), 422, 'validation_failed');
    }

    public function test_fr_012_types_follow_the_users_permissions_and_br_037_the_company(): void
    {
        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->assertSame(['party', 'sales_invoice', 'product'], array_keys($this->hits($this->req('/api/v1/search?q=Karim', $sales))));
        $this->assertSame([], $this->req('/api/v1/search?q=BEO&type=purchase_bill', $sales)->json('data.groups'));

        $this->userWithRole('store');
        $this->assertSame(['purchase_bill', 'product'], array_keys($this->hits($this->req('/api/v1/search?q=oil', $this->login('store@meghna.example')))));

        // Another company's records never show.
        $mine = $this->books;
        $this->books = $this->makeCompany('Padma Foods Ltd.', $this->makeUser('owner@padma.example'));
        $this->customer('Karim Brothers', '01711999000');
        $this->books = $mine;
        $this->useBooks();
        $this->assertSame(1, $this->hits($this->req('/api/v1/search?q=Karim&type=party'))['party']['count']);
    }
}
