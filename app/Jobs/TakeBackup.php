<?php

namespace App\Jobs;

use App\Domain\Backups\BackupService;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One company snapshot (K15; backlog P15.1-BE-09). @see BackupService::snapshot */
class TakeBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $companyId, public string $kind, public ?int $userId = null) {}

    public function handle(BackupService $backups, CurrentCompany $current): int
    {
        $previous = $current->has() ? [$current->company(), $current->membership()] : null;
        $current->set(Company::findOrFail($this->companyId), null);
        try {
            return $backups->snapshot($this->kind, $this->userId);
        } finally {
            $previous ? $current->set(...$previous) : $current->clear();
        }
    }
}
