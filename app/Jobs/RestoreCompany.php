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

/** Restores a company from one of its backups (BR-062; backlog P15.1-BE-09). Never retried: a restore runs once. */
class RestoreCompany implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $companyId, public int $backupId, public int $userId) {}

    public function handle(BackupService $backups, CurrentCompany $current): void
    {
        $previous = $current->has() ? [$current->company(), $current->membership()] : null;
        $current->set(Company::findOrFail($this->companyId), null);
        try {
            $backups->restore($this->backupId, $this->userId);
        } finally {
            $previous ? $current->set(...$previous) : $current->clear();
        }
    }
}
