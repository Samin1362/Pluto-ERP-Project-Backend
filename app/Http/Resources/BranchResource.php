<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Branch (05 schemas): a location used in pickers and report filters. */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_head_office' => (bool) $this->is_head_office,
            'is_active' => (bool) $this->is_active,
            'version' => (int) $this->lock_version,
        ];
    }
}
