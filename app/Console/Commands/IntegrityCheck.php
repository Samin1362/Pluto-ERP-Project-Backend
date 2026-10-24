<?php

namespace App\Console\Commands;

use App\Domain\Accounting\BooksIntegrity;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly books consistency check (NFR-042, 11 §6; backlog P4.1-BE-08): the same checks the tests run after every
 * posting (BooksIntegrity, 09 §8), per company. Prints one row per check, lists what is wrong, logs the result and
 * exits 1 on any mismatch. Scheduled at 02:00 Dhaka (routes/console.php); `make integrity` runs it by hand.
 */
class IntegrityCheck extends Command
{
    protected $signature = 'pluto:integrity {--company= : Only this company id}';

    protected $description = 'Check that the books are consistent (balanced journals, stock = movements = inventory ledgers)';

    public function handle(BooksIntegrity $integrity): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->error('No such company');

            return self::FAILURE;
        }
        $rows = [];
        $problems = [];
        foreach ($companies as $company) {
            foreach ($integrity->check($company->id) as $check => $failures) {
                $rows[] = ["#{$company->id} {$company->legal_name}", $check, $failures ? count($failures).' failed' : 'OK'];
                foreach ($failures as $f) {
                    $problems[] = "#{$company->id} {$check}: {$f}";
                }
            }
        }
        $this->table(['Company', 'Check', 'Result'], $rows);
        foreach ($problems as $p) {
            $this->line("  <error>✗</error> {$p}");
        }
        if ($problems) {
            Log::error('Books integrity check failed', ['failures' => $problems]);
            $this->error(count($problems).' problem(s) found.');

            return self::FAILURE;
        }
        Log::info('Books integrity check passed', ['companies' => $companies->count()]);
        $this->info('All books are consistent.');

        return self::SUCCESS;
    }
}
