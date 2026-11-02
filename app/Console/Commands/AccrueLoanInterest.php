<?php

namespace App\Console\Commands;

use App\Domain\Lc\LcFinanceService;
use App\Models\Company;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Month-end interest on open PAD/LTR loans (P16 LC finance; 06 §12.12): Dr interest expense / Cr interest payable for the
 * days since each loan's last interest date. Runs on the 1st at 00:45 Dhaka for the month just ended; the next repayment
 * clears what was accrued.
 */
class AccrueLoanInterest extends Command
{
    protected $signature = 'pluto:loans:accrue {--company= : Only this company id} {--date= : Accrue through this date (YYYY-MM-DD); default the last day of last month}';

    protected $description = 'Accrue interest on open PAD/LTR loans to the month end';

    public function handle(LcFinanceService $finance, CurrentCompany $current): int
    {
        $through = $this->option('date') ?: CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subDay()->toDateString();
        $failed = false;
        foreach (Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get() as $company) {
            $current->set($company, null);
            try {
                $this->line("#{$company->id} {$company->legal_name}: {$finance->accrueAll($through)} accrual(s) through {$through}");
            } catch (Throwable $e) {
                report($e);
                $failed = true;
                $this->warn("#{$company->id}: {$e->getMessage()}");
            }
        }
        $current->clear();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
