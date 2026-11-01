<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Backups\BackupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RestoreRequest;
use App\Jobs\RestoreCompany;
use App\Jobs\TakeBackup;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /backups (K15; FR-087; BR-062; R-11; backlog P15.1-BE-09). History and manual backup: settings.backup; download and
 * restore: settings.restore (Owner). Taking and restoring run on the queue (202); the list shows their progress.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups, private readonly CurrentCompany $current) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('company_backups')->where('company_id', $this->current->id())->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($b) => BackupService::resource($b))->all();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function store(): JsonResponse
    {
        if (DB::table('company_backups')->where('company_id', $this->current->id())->where('status', 'running')->where('started_at', '>', now()->subMinutes(30))->exists()) {
            throw new ApiProblem('invalid_transition', 'A backup is already running; wait for it to finish.');
        }
        $id = app()->call([new TakeBackup($this->current->id(), 'manual', (int) auth()->id()), 'handle']);

        return response()->json(['data' => BackupService::resource(DB::table('company_backups')->find($id))], 202);
    }

    public function download(int $id): JsonResponse
    {
        return response()->json(['data' => $this->backups->downloadUrl($id)])->header('Cache-Control', 'no-store');
    }

    public function restore(RestoreRequest $request, int $id): JsonResponse
    {
        $b = DB::table('company_backups')->where('company_id', $this->current->id())->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        RestoreCompany::dispatch($this->current->id(), $id, (int) auth()->id());

        return response()->json(['data' => BackupService::resource($b)], 202);
    }

    /** The file behind the 5-minute signed link (outside JWT). */
    public function file(int $id): StreamedResponse
    {
        $b = DB::table('company_backups')->where('id', $id)->where('status', 'complete')->first() ?? abort(404);

        return Storage::disk('local')->download($b->storage_path, "pluto-backup-{$b->company_id}-".basename($b->storage_path),
            ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store']);
    }
}
