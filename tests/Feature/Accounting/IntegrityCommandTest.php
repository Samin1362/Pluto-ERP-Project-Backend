<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** `pluto:integrity` (NFR-042, TC-NFR-007 part; backlog P4.1-BE-08). */
class IntegrityCommandTest extends TestCase
{
    use BuildsMasterData;

    private function receive(): void
    {
        $w = $this->warehouse();
        $p = $this->product();
        DB::transaction(function () use ($w, $p) {
            app(StockService::class)->stockIn($p, $w, Quantity::of('740'), Money::of('626000.00'), $w, '2026-07-05');
            app(PostingService::class)->persist((new JournalBuilder)->debit($w->inventory_ledger_id, Money::of('626000.00'))
                ->credit(Ledger::where('code', '9999')->firstOrFail(), Money::of('626000.00')), null, 'stock_adjustment', '2026-07-05');
        });
    }

    public function test_consistent_books_pass_for_every_company(): void
    {
        $this->provisionedCompany();
        $this->receive();
        $this->provisionedCompany('Padma Agro Ltd.', 'padma@agro.example'); // a second, empty company

        $this->artisan('pluto:integrity')->expectsOutputToContain('All books are consistent.')->assertSuccessful();
        $this->artisan('pluto:integrity', ['--company' => $this->books->id])->assertSuccessful();
    }

    public function test_tc_nfr_007_a_manually_corrupted_balance_fails_the_check(): void
    {
        $this->provisionedCompany();
        $this->receive();
        DB::table('stock_balances')->update(['total_value' => '625000.00']);

        $this->artisan('pluto:integrity')
            ->expectsOutputToContain('value 625000.00, movements 626000.00')
            ->expectsOutputToContain('1301-01: ledger 626000.00, stock 625000.00')
            ->expectsOutputToContain('2 problem(s) found.')
            ->assertExitCode(1);
    }

    public function test_an_unknown_company_is_an_error(): void
    {
        $this->artisan('pluto:integrity', ['--company' => 999999])->expectsOutputToContain('No such company')->assertExitCode(1);
    }
}
