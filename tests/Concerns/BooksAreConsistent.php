<?php

namespace Tests\Concerns;

use App\Domain\Accounting\BooksIntegrity;
use Illuminate\Support\Facades\DB;

/**
 * 09 §8: after every posting feature test the books must be consistent for every company the test touched — posted
 * lines balance, entries match their lines and their documents, stock balances equal their movements in quantity
 * and value, and each inventory ledger equals the stock value behind it (backlog P4.1-BE-07). Opt-in: `use` it in a
 * posting test; it runs in tearDown (before the database is rolled back).
 */
trait BooksAreConsistent
{
    protected function tearDownBooksAreConsistent(): void
    {
        $this->assertBooksAreConsistent();
    }

    protected function assertBooksAreConsistent(): void
    {
        $integrity = app(BooksIntegrity::class);
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $failures = $integrity->failures((int) $companyId);
            $this->assertSame([], $failures, "Books of company {$companyId} are not consistent:\n".implode("\n", $failures));
        }
    }

    protected function tearDown(): void
    {
        if ($this->status()->isSuccess()) { // a failed test's own message matters more
            $this->tearDownBooksAreConsistent();
        }
        parent::tearDown();
    }
}
