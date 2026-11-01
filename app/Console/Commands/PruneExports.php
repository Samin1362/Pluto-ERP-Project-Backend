<?php

namespace App\Console\Commands;

use App\Domain\Exports\ExportJobService;
use Illuminate\Console\Command;

/** Hourly: removes export files 24 hours after their job finished (backlog P14.1-BE-05). */
class PruneExports extends Command
{
    protected $signature = 'pluto:exports:prune';

    protected $description = 'Remove export files older than 24 hours';

    public function handle(): int
    {
        $n = ExportJobService::prune();
        $this->line("Removed {$n} export file".($n === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
