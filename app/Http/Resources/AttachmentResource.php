<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Attachment (05 schemas; FR-066): file metadata; the bytes come through GET /attachments/{id}/url. */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => $a->id, 'original_name' => $a->original_name, 'mime_type' => $a->mime_type, 'size_bytes' => (int) $a->size_bytes,
            'uploaded_by' => ['id' => (int) $a->created_by, 'name' => $a->uploader?->name ?? ''],
            'created_at' => $a->created_at->toIso8601String(),
        ];
    }
}
