<?php

namespace App\Http\Controllers\Api\V1\Attachments;

use App\Domain\Attachments\AttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\AttachmentListRequest;
use App\Http\Requests\Attachments\AttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /attachments (FR-066; backlog P13.1-BE-01): upload, list a record's files, a 5-minute signed link, remove. Permission
 * follows the record the file belongs to (AttachmentService). The signed link is served by file(), outside the JWT
 * group: the signature is the credential.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments, private readonly CurrentCompany $current) {}

    public function index(AttachmentListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $files = $this->attachments->listFor($f['attachable_type'], (int) $f['attachable_id'], $this->current->membership());

        return response()->json(['data' => AttachmentResource::collection($files)->resolve($request),
            'meta' => ['current_page' => 1, 'per_page' => max(1, $files->count()), 'total' => $files->count(), 'last_page' => 1]]);
    }

    public function store(AttachmentRequest $request): JsonResponse
    {
        $f = $request->validated();
        $a = $this->attachments->store($request->file('file'), $f['attachable_type'], isset($f['attachable_id']) ? (int) $f['attachable_id'] : null,
            $this->current->membership());
        $a->load('uploader');

        return response()->json(['data' => (new AttachmentResource($a))->resolve($request)], 201)->header('Location', "/api/v1/attachments/{$a->id}");
    }

    public function url(int $id): JsonResponse
    {
        return response()->json(['data' => $this->attachments->signedUrl(Attachment::findOrFail($id), $this->current->membership())]);
    }

    public function destroy(int $id): Response
    {
        $this->attachments->delete(Attachment::findOrFail($id), $this->current->membership());

        return response()->noContent();
    }

    /** GET /attachments/{id}/file?expires&signature: the bytes, inline, for whoever holds a valid link. */
    public function file(int $id): StreamedResponse
    {
        $a = Attachment::withoutGlobalScopes()->findOrFail($id);

        return Storage::disk($a->storage_disk)->response($a->storage_path, $a->original_name, [
            'Content-Type' => $a->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
