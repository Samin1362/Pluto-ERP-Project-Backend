<?php

namespace App\Console\Commands;

use App\Domain\Accounting\FiscalYearService;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Opens each company's next financial year 30 days before the current one ends (R-05; 06 §16; backlog P7.1-BE-07),
 * so the new year can be posted while the old one is still being closed. Daily at 01:30 Dhaka (routes/console.php).
 */
class OpenNextYear extends Command
{
    protected $signature = 'pluto:open-next-year {--company= : Only this company id}';

    protected $description = 'Create the next financial year (periods, number series) when the current one ends within 30 days';

    public function handle(FiscalYearService $years, CurrentCompany $current): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        foreach ($companies as $company) {
            $current->set($company, null);
            $year = $years->openNext();
            $this->line("#{$company->id} {$company->legal_name}: ".($year ? "opened {$year->name}" : 'nothing to open'));
        }

        return self::SUCCESS;
    }
}
