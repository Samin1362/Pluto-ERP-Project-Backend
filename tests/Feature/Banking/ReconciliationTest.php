<?php

namespace Tests\Feature\Banking;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Bank statement import and reconciliation (G10; CF-19; BR-032, BR-065; TC-BNK-003, TC-BNK-004, TC-BNK-005;
 * backlog P12.1-BE-04/05).
 */
class ReconciliationTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private array $bank;

    private string $d0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '100000.00'])->assertCreated()->json('data');
        $this->d0 = CarbonImmutable::now('Asia/Dhaka')->subDays(5)->toDateString();
        // Book: a customer deposit of 20,000 (reference CHQ-1001) and a payment of 7,500
        $this->voucher('receipt', '20000.00', '3101', $this->d0, 'CHQ-1001');
        $this->voucher('payment', '7500.00', '6101', $this->day(1), null);
    }

    private function day(int $after): string
    {
        return CarbonImmutable::parse($this->d0)->addDays($after)->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function voucher(string $type, string $amount, string $ledgerCode, string $date, ?string $ref): void
    {
        $this->req('POST', '/api/v1/vouchers', ['entry_type' => $type, 'action' => 'post', 'date' => $date, 'confirm_backdated' => true,
            'payment' => array_filter(['money_account_id' => $this->bank['id'], 'method' => 'bank_transfer', 'transaction_reference' => $ref]),
            'lines' => [['ledger_id' => DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $ledgerCode)->value('id'), 'amount' => $amount]]])->assertCreated();
    }

    private function upload(string $name, string $csv, ?array $mapping = null)
    {
        $data = ['money_account_id' => (string) $this->bank['id']] + ($mapping ? ['mapping' => $mapping] : []);
        $r = $this->call('POST', '/api/v1/bank-statement-imports', $data, [], ['file' => UploadedFile::fake()->createWithContent($name, $csv)],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}", 'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json',
                'Idempotency-Key' => (string) Str::uuid()]));
        $this->useBooks();

        return $r;
    }

    /** Statement: the deposit (2 days late), the payment, a bank charge of 115 and a cheque that bounced (+5,000 then −5,000). */
    private function statement(): string
    {
        $d = fn ($n) => CarbonImmutable::parse($this->day($n))->format('d/m/Y');

        return "Txn Date,Particulars,Ref No,Withdrawal,Deposit\n"
            ."{$d(2)},CLG DEPOSIT,CHQ-1001,,\"20,000.00\"\n"
            ."{$d(1)},TRF TO LANDLORD,,7500.00,\n"
            ."{$d(3)},SMS AND SERVICE CHARGE,,115.00,\n"
            ."{$d(3)},CHQ DEPOSIT,CHQ-2002,,5000.00\n"
            ."{$d(4)},CHQ RETURNED,CHQ-2002,5000.00,\n";
    }

    public function test_tc_bnk_003_a_statement_is_imported_once(): void
    {
        $r = $this->upload('sonali-oct.csv', $this->statement())->assertCreated();
        $r->assertJsonPath('data.line_count', 5)->assertJsonPath('data.duplicates_skipped', 0)->assertJsonPath('data.period_from', $this->day(1))
            ->assertJsonPath('data.period_to', $this->day(4));
        $this->assertSame(['20000.00', '-7500.00', '-115.00'], DB::table('bank_statement_lines')->orderBy('line_no')->limit(3)->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertProblem($this->upload('copy.csv', $this->statement()), 409, 'duplicate_statement');
        // A different file repeating a line skips it
        $this->upload('more.csv', "Date,Description,Amount\n{$this->day(4)},CHQ RETURNED,-5000.00\n{$this->day(4)},INTEREST,12.50\n")->assertCreated()
            ->assertJsonPath('data.line_count', 2)->assertJsonPath('data.duplicates_skipped', 0);
        $this->assertProblem($this->upload('bad.csv', "Foo,Bar\n1,2\n"), 422, 'validation_failed');
        $this->assertProblem($this->upload('st.pdf', 'x'), 415, 'file_type_not_allowed');
    }

    public function test_tc_bnk_004_reconcile_to_zero_and_finish(): void
    {
        $this->upload('sonali-oct.csv', $this->statement())->assertCreated();
        $closing = '112385.00'; // 100,000 + 20,000 − 7,500 − 115 + 5,000 − 5,000
        $r = $this->req('POST', '/api/v1/reconciliations', ['money_account_id' => $this->bank['id'], 'statement_date' => $this->day(4), 'statement_balance' => $closing])->assertOk();
        $id = $r->json('data.id');
        $r->assertJsonPath('data.line_count', 5)->assertJsonPath('data.unhandled_count', 5)->assertJsonPath('data.book_balance', '112500.00')
            ->assertJsonPath('data.difference', '12385.00')->assertJsonPath('data.money_account_name', $this->bank['name']); // nothing cleared yet
        $this->assertCount(2, $r->json('data.book_entries'));
        $lines = collect($r->json('data.lines'))->keyBy('description');
        $this->assertSame('CHQ-1001', $lines['CLG DEPOSIT']['suggestion']['reference']);
        $this->assertNotNull($lines['TRF TO LANDLORD']['suggestion']);
        $this->assertNull($lines['SMS AND SERVICE CHARGE']['suggestion']);

        $this->assertProblem($this->req('POST', "/api/v1/reconciliations/{$id}/finish"), 422, 'lines_unhandled');
        $this->req('POST', "/api/v1/reconciliations/{$id}/accept-suggestions")->assertOk()->assertJsonPath('data.unhandled_count', 3)
            ->assertJsonPath('data.difference', '-115.00')->assertJsonPath('data.book_entries', []); // only the bank charge is missing from the book
        $this->req('POST', "/api/v1/reconciliations/{$id}/lines/pair", ['line_ids' => [$lines['CHQ DEPOSIT']['id'], $lines['CHQ RETURNED']['id']]])->assertOk()
            ->assertJsonPath('data.unhandled_count', 1);
        // Ignoring the charge handles every line but leaves the difference: cannot finish
        $this->req('POST', "/api/v1/reconciliations/{$id}/lines/pair", ['line_ids' => [$lines['SMS AND SERVICE CHARGE']['id']]])->assertOk()->assertJsonPath('data.unhandled_count', 0);
        $this->assertProblem($this->req('POST', "/api/v1/reconciliations/{$id}/finish"), 422, 'difference_not_zero');
        $this->req('POST', "/api/v1/reconciliations/{$id}/lines/{$lines['SMS AND SERVICE CHARGE']['id']}/undo")->assertOk()->assertJsonPath('data.unhandled_count', 1);

        // Record the charge: a payment voucher Dr 6702 / Cr bank, matched
        $c = $this->req('POST', "/api/v1/reconciliations/{$id}/lines/{$lines['SMS AND SERVICE CHARGE']['id']}/create-entry",
            ['kind' => 'bank_charge', 'ledger_id' => DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '6702')->value('id')])->assertOk();
        $c->assertJsonPath('data.difference', '0.00')->assertJsonPath('data.unhandled_count', 0);
        $charge = collect($c->json('data.lines'))->firstWhere('description', 'SMS AND SERVICE CHARGE');
        $this->assertSame('created', $charge['status']);
        $this->assertMatchesRegularExpression('/^PV-/', $charge['matched']['voucher_number']);

        $this->req('POST', "/api/v1/reconciliations/{$id}/finish")->assertOk()->assertJsonPath('data.status', 'finished');
        $this->assertSame($this->day(4), (string) DB::table('money_accounts')->where('id', $this->bank['id'])->value('last_reconciled_on'));
        $this->assertSame('reconciled', DB::table('bank_statement_imports')->value('status'));
        $this->assertProblem($this->req('POST', "/api/v1/reconciliations/{$id}/accept-suggestions"), 409, 'invalid_transition');
        $this->assertProblem($this->req('POST', '/api/v1/reconciliations', ['money_account_id' => $this->bank['id'], 'statement_date' => $this->day(4), 'statement_balance' => $closing]),
            422, 'validation_failed');
    }

    public function test_tc_bnk_005_a_book_line_matches_one_statement_line(): void
    {
        $this->upload('dup.csv', "Date,Description,Reference,Amount\n{$this->day(1)},TRF A,R1,-7500.00\n{$this->day(1)},TRF B,R2,-7500.00\n")->assertCreated();
        $r = $this->req('POST', '/api/v1/reconciliations', ['money_account_id' => $this->bank['id'], 'statement_date' => $this->day(1), 'statement_balance' => '92500.00'])->assertOk();
        $id = $r->json('data.id');
        $bookLine = collect($r->json('data.book_entries'))->firstWhere('amount', '-7500.00')['journal_line_id'];
        [$a, $b] = array_column($r->json('data.lines'), 'id');
        $this->req('POST', "/api/v1/reconciliations/{$id}/lines/{$a}/match", ['journal_line_id' => $bookLine])->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/reconciliations/{$id}/lines/{$b}/match", ['journal_line_id' => $bookLine]), 409, 'duplicate');
        $this->assertProblem($this->req('POST', "/api/v1/reconciliations/{$id}/lines/{$b}/match",
            ['journal_line_id' => collect($r->json('data.book_entries'))->firstWhere('amount', '20000.00')['journal_line_id'] ?? 0]), 422, 'validation_failed');

        $this->userWithRole('sales', 'sales@meghna.example');
        $this->assertProblem($this->req('GET', "/api/v1/reconciliations/{$id}", [], $this->login('sales@meghna.example')), 403, 'permission_denied');
    }
}
