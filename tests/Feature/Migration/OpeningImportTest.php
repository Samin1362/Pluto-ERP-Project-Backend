<?php

namespace Tests\Feature\Migration;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * pluto:import:opening (backlog P17.1-BE-05; BR-028): the templates in docs/import-templates import as they are; a dry
 * run saves nothing; one bad row keeps the whole file out and lands in the error report; a second run never doubles.
 */
class OpeningImportTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    private function opening(string $code): string
    {
        $id = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');

        return (string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.ledger_id', $id)
            ->where('e.entry_type', 'opening_balance')->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) b')->value('b');
    }

    public function test_the_templates_import_and_post_opening_entries_at_the_year_start(): void
    {
        foreach (['customers', 'suppliers', 'ledgers', 'banks'] as $type) {
            $this->artisan('pluto:import:opening', ['type' => $type, 'file' => base_path("docs/import-templates/{$type}.csv"), '--company' => $this->books->id])
                ->expectsOutputToContain('imported')->assertSuccessful();
        }
        $this->useBooks();
        $karim = DB::table('parties')->where('company_id', $this->books->id)->where('name', 'Karim Store')->first();
        $this->assertSame('50000.00', $karim->credit_limit);
        $this->assertSame('12500.00', $this->openingOf($karim->ledger_id));
        $bengal = DB::table('parties')->where('company_id', $this->books->id)->where('name', 'Bengal Edible Oils')->first();
        $this->assertSame('-80000.00', $this->openingOf($bengal->ledger_id));
        $this->assertSame('350000.00', $this->opening('1501'));
        $this->assertSame('-1500000.00', $this->opening('3101'));
        $this->assertSame('-750000.00', $this->opening('2401'));
        $this->assertSame(2, DB::table('money_accounts')->where('company_id', $this->books->id)->whereIn('name', ['Main cash'])->orWhere('account_number', '0012345678')->count());
        $fyStart = DB::table('fiscal_years')->where('company_id', $this->books->id)->orderByDesc('start_date')->value('start_date');
        $this->assertSame(0, DB::table('journal_entries')->where('company_id', $this->books->id)->where('entry_type', 'opening_balance')->where('entry_date', '!=', $fyStart)->count());
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('company_id', $this->books->id)->where('auditable_type', 'parties')->count());

        // Opening stock of an existing product; then the same row again is refused (BR-028 lock).
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar');
        $stock = $this->csv("product_code,warehouse_code,quantity,unit_cost\n{$oil->code},{$wh->code},120,850.0000\n");
        $this->artisan('pluto:import:opening', ['type' => 'stock', 'file' => $stock, '--company' => $this->books->id])->assertSuccessful();
        $this->useBooks();
        $this->assertSame('120.000', DB::table('stock_balances')->where('product_id', $oil->id)->where('warehouse_id', $wh->id)->value('qty_on_hand'));
        $this->artisan('pluto:import:opening', ['type' => 'stock', 'file' => $stock, '--company' => $this->books->id])
            ->expectsOutputToContain('opening stock is locked')->assertFailed();
        $this->useBooks();
        $this->assertBooksAreConsistent();
    }

    private function openingOf(int $ledgerId): string
    {
        return (string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.ledger_id', $ledgerId)
            ->where('e.entry_type', 'opening_balance')->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) b')->value('b');
    }

    public function test_a_dry_run_saves_nothing_and_one_bad_row_keeps_the_file_out(): void
    {
        $count = fn () => DB::table('parties')->where('company_id', $this->books->id)->count();
        $before = $count();
        $good = $this->csv("name,mobile,opening_balance,opening_side\nRahim Store,01711000011,1000.00,dr\nSalam Traders,01711000012,,\n");
        $this->artisan('pluto:import:opening', ['type' => 'customers', 'file' => $good, '--company' => $this->books->id, '--dry-run' => true])
            ->expectsOutputToContain('2 would be imported (dry run, nothing saved)')->assertSuccessful();
        $this->assertSame($before, $count());

        $bad = $this->csv("name,mobile,opening_balance,opening_side\nRahim Store,01711000011,1000.00,dr\n,01711000013,abc,up\nRahim Store,01711000011,5.00,dr\n");
        $report = sys_get_temp_dir().'/import-errors-'.uniqid().'.csv';
        $this->artisan('pluto:import:opening', ['type' => 'customers', 'file' => $bad, '--company' => $this->books->id, '--errors' => $report])
            ->expectsOutputToContain('nothing saved because of the errors')->assertFailed();
        $this->assertSame($before, $count());
        $lines = array_map('str_getcsv', file($report, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['row', 'column', 'message'], $lines[0]);
        $this->assertContains(['3', 'name', 'Enter the name'], $lines);
        $this->assertContains(['3', 'opening_side', 'Use dr or cr'], $lines);
        $this->assertContains(['4', 'name', 'Same name and mobile as row 2'], $lines);

        $this->artisan('pluto:import:opening', ['type' => 'customers', 'file' => $good, '--company' => $this->books->id])->assertSuccessful();
        $this->artisan('pluto:import:opening', ['type' => 'customers', 'file' => $good, '--company' => $this->books->id])
            ->expectsOutputToContain('Already in the books')->assertFailed();
        $this->assertSame($before + 2, $count());

        $this->artisan('pluto:import:opening', ['type' => 'customers', 'file' => $this->csv("name,phone\nX,1\n"), '--company' => $this->books->id])
            ->expectsOutputToContain('Unknown columns for customers: phone')->assertFailed();
    }
}
