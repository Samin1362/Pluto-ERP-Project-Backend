<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Exports\ExportJobService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ExportJobRequest;
use App\Models\ExportJob;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /export-jobs (J1, K15, list and report pages; FR-076, FR-087; backlog P14.1-BE-05). Route permission reports.export;
 * a job is seen only by the user who started it. The file comes from a signed link (24 h, relative signature).
 */
class ExportJobController extends Controller
{
    public function __construct(private readonly ExportJobService $exports, private readonly CurrentCompany $current) {}

    public function store(ExportJobRequest $request): JsonResponse
    {
        $job = $this->exports->create($request->validated(), $this->current->membership(), (int) $request->attributes->get('auth_session_id'));

        return response()->json(['data' => $this->exports->resource($job->refresh())], 202)->header('Location', url("/api/v1/export-jobs/{$job->id}"));
    }

    public function show(int $id): JsonResponse
    {
        $job = ExportJob::where('created_by', auth()->id())->find($id) ?? throw new ApiProblem('not_found');

        return response()->json(['data' => $this->exports->resource($job)]);
    }

    /** The file behind GET /export-jobs/{id}.download_url (outside JWT; the signature is the permission). */
    public function file(Request $request, int $id): StreamedResponse
    {
        $job = ExportJob::withoutGlobalScopes()->whereNotNull('file_path')->findOrFail($id);
        if (! Storage::disk('local')->exists($job->file_path)) {
            throw new ApiProblem('not_found', 'This export has expired; export again.');
        }
        $name = $job->parameters['file_name'] ?? basename($job->file_path);
        $type = match (pathinfo($name, PATHINFO_EXTENSION)) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', default => 'application/octet-stream',
        };

        return Storage::disk('local')->download($job->file_path, $name, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
