<?php

namespace App\Console\Commands;

use App\Domain\Lc\LcService;
use App\Models\Company;
use App\Models\LetterOfCredit;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Daily at 07:00 Dhaka (11 §6; 06 §12.1): opened or partly shipped LCs past their expiry date become expired (margin released). */
class ExpireLcs extends Command
{
    protected $signature = 'pluto:lc:expire {--company= : Only this company id} {--date= : As of this date (YYYY-MM-DD)}';

    protected $description = 'Expire LCs past their expiry date';

    public function handle(LcService $lcs, CurrentCompany $current): int
    {
        $today = $this->option('date') ?: CarbonImmutable::now('Asia/Dhaka')->toDateString();
        foreach (Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get() as $company) {
            $current->set($company, null);
            $n = 0;
            foreach (LetterOfCredit::whereIn('status', ['opened', 'partly_shipped'])->where('expiry_date', '<', $today)->orderBy('id')->get() as $lc) {
                try {
                    $lcs->transition($lc->id, ['action' => 'expire', 'reason' => "Expiry date {$lc->expiry_date->toDateString()} passed"], (int) $lc->lock_version);
                    $n++;
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("{$lc->lc_number}: {$e->getMessage()}");
                }
            }
            $this->line("#{$company->id} {$company->legal_name}: {$n} LC(s) expired");
        }
        $current->clear();

        return self::SUCCESS;
    }
}
