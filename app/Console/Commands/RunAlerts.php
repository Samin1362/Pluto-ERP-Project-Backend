<?php

namespace App\Console\Commands;

use App\Domain\Notifications\AlertService;
use App\Models\Company;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily alerts at 07:30 Asia/Dhaka (11 §6; backlog P14.1-BE-04): invoices that fell overdue and bills due in 3 days,
 * in-app (and by e-mail for those who asked). `--summary` sends the daily summary e-mails instead (08:00).
 */
class RunAlerts extends Command
{
    protected $signature = 'pluto:alerts:run {--company= : Only this company id} {--date= : Run as of this date (YYYY-MM-DD), default today in Dhaka} {--summary : Send the daily summary e-mails} {--at= : With --summary: only users who chose this time (07:30, 08:00 or 09:00)}';

    protected $description = 'Overdue-invoice and bills-due alerts, or the daily summary e-mails';

    public function handle(AlertService $alerts, CurrentCompany $current): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date'), 'Asia/Dhaka') : CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        foreach ($companies as $company) {
            $current->set($company, null);
            if ($this->option('summary')) {
                $n = $alerts->dailySummary($today, $this->option('at') ?: null);
                $this->line("#{$company->id} {$company->legal_name}: {$n} summary e-mail".($n === 1 ? '' : 's'));
            } else {
                $r = $alerts->run($today);
                $this->line("#{$company->id} {$company->legal_name}: {$r['overdue']} overdue invoice(s), {$r['due_soon']} bill(s) due in 3 days, {$r['lc']} LC deadline alert(s)");
            }
        }
        $current->clear();

        return self::SUCCESS;
    }
}
