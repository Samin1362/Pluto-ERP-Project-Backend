<?php

namespace Tests\Feature\Parties;

use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Party opening balances (B2/C2; BR-028; docs 15 D4 resolved; TC-ACC-014; backlog P6.1-BE-06). */
class PartyOpeningBalanceTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [])
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function fyStart(): string
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();

        return FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->value('start_date')->toDateString();
    }

    public function test_tc_acc_014_a_customer_opening_of_1_85_000_dr_is_locked_after_the_first_invoice(): void
    {
        $r = $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman Super Shop', 'mobile' => '01811000222', 'opening_balance' => '185000.00'])
            ->assertCreated();
        $r->assertJsonPath('data.opening_balance', '185000.00')->assertJsonPath('data.balance', '185000.00')->assertJsonPath('data.balance_side', 'dr')
            ->assertJsonPath('data.opening_locked', false);
        $id = $r->json('data.id');
        $ledger = (int) \DB::table('parties')->where('id', $id)->value('ledger_id');
        $entry = JournalEntry::with('lines')->where('source_type', 'parties')->where('source_id', $id)->sole();
        $this->assertSame(['opening_balance', $this->fyStart()], [$entry->entry_type, $entry->entry_date->toDateString()]);
        $this->assertSame([[$ledger, '185000.00', '0.00'], [Ledger::where('code', '3301')->value('id'), '0.00', '185000.00']],
            $entry->lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->all());

        // Still editable: the old entry is reversed and a new one posted.
        $this->req('PATCH', "/api/v1/parties/{$id}", ['opening_balance' => '180000.00'], ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.opening_balance', '180000.00')->assertJsonPath('data.balance', '180000.00');
        $this->assertSame(1, JournalEntry::where('entry_type', 'reversal')->count());

        // After the first invoice the opening is locked.
        $this->useBooks();
        $wh = $this->warehouse();
        $trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        app(SalesInvoicePostingService::class)->post(app(SalesInvoiceService::class)->createDraft(['party_id' => $id, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $wh->id, 'lines' => [['product_id' => $trip->id, 'quantity' => '500', 'unit_price' => '1.00']]]));
        $v = $this->req('GET', "/api/v1/parties/{$id}")->assertJsonPath('data.opening_locked', true)->assertJsonPath('data.balance', '180500.00')->json('data.version');
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['opening_balance' => '1.00'], ['If-Match' => "W/\"{$v}\""]), 422, 'validation_failed')
            ->assertJsonPath('errors.opening_balance.0', 'The opening balance is locked: this account already has entries (BR-028)');
        $this->req('PATCH', "/api/v1/parties/{$id}", ['opening_balance' => '180000.00', 'notes' => 'Same opening'], ['If-Match' => "W/\"{$v}\""])->assertOk(); // unchanged is fine
    }

    public function test_a_supplier_opening_is_a_credit_unless_dr_is_chosen(): void
    {
        $sup = $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333', 'opening_balance' => '75000.00'])
            ->assertCreated();
        $sup->assertJsonPath('data.opening_balance', '-75000.00')->assertJsonPath('data.balance', '75000.00')->assertJsonPath('data.balance_side', 'cr');
        $adv = $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Meghna Packaging', 'mobile' => '01811000333', 'opening_balance' => '5000.00',
            'opening_side' => 'dr'])->assertCreated();
        $adv->assertJsonPath('data.balance_side', 'dr')->assertJsonPath('data.balance', '5000.00');
        $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Zero Shop', 'mobile' => '01711000444'])->assertCreated();
        $this->assertSame(2, JournalEntry::where('entry_type', 'opening_balance')->count(), 'no entry for a zero opening');

        // An advance opening of a supplier can be applied to its bills later (it is a settling line).
        $credits = $this->req('GET', '/api/v1/parties/'.$adv->json('data.id').'/open-documents')->json('data.credits');
        $this->assertSame([['advance', '5000.00']], array_map(fn ($c) => [$c['kind'], $c['unapplied']], $credits));
    }
}
