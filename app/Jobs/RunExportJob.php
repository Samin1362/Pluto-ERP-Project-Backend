<?php

namespace App\Jobs;

use App\Domain\Exports\ExportJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs one export job (NFR-006; backlog P14.1-BE-05). The service records failure on the job itself, so no retries. */
class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $exportJobId) {}

    public function handle(ExportJobService $service): void
    {
        $service->run($this->exportJobId);
    }
}
