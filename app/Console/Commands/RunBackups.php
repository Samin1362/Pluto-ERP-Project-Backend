<?php

namespace App\Console\Commands;

use App\Domain\Backups\BackupService;
use App\Jobs\TakeBackup;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly per-company backups at 00:30 Dhaka for companies with backups on (K15, 11 §6), then retention clean-up. */
class RunBackups extends Command
{
    protected $signature = 'pluto:backups:run {--company= : Only this company id}';

    protected $description = 'Take the automatic company backups and remove expired ones';

    public function handle(): int
    {
        $companies = Company::query()->where('status', 'active')->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->pluck('id');
        foreach ($companies as $id) {
            if (! DB::table('company_settings')->where('company_id', $id)->value('backup_enabled')) {
                continue;
            }
            $backup = app()->call([new TakeBackup($id, 'automatic'), 'handle']);
            $this->line("#{$id}: backup #{$backup} ".DB::table('company_backups')->where('id', $backup)->value('status'));
        }
        $n = BackupService::prune();
        $this->line("Removed {$n} expired backup".($n === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
