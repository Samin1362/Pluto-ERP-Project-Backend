<?php

namespace App\Console\Commands;

use App\Domain\Attachments\AttachmentService;
use App\Domain\Banking\IncomeExpenseService;
use App\Models\Company;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recurring income and expenses (I10; backlog P13.1-BE-04; BR-048): for each company, a draft voucher for every
 * template due today or earlier — never posted — then the next date. Also removes attachment uploads nobody saved an
 * entry with (older than a day). Daily at 06:00 Dhaka (routes/console.php).
 */
class RunRecurring extends Command
{
    protected $signature = 'pluto:recurring:run {--company= : Only this company id} {--date= : Run as of this date (YYYY-MM-DD), default today in Dhaka}';

    protected $description = 'Create the drafts of recurring income and expenses that are due';

    public function handle(IncomeExpenseService $service, CurrentCompany $current): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date'), 'Asia/Dhaka') : CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        foreach ($companies as $company) {
            $current->set($company, null);
            $skipped = [];
            $made = $service->run($today, $skipped);
            $this->line("#{$company->id} {$company->legal_name}: {$made} draft".($made === 1 ? '' : 's'));
            foreach ($skipped as $id => $why) {
                $this->warn("  recurring entry #{$id} skipped: {$why}");
            }
        }
        $current->clear();
        $pruned = AttachmentService::prunePending();
        if ($pruned > 0) {
            $this->line("Removed {$pruned} unsaved upload".($pruned === 1 ? '' : 's'));
        }

        return self::SUCCESS;
    }
}
