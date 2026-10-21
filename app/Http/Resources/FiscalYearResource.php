<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** FiscalYear (05 schemas) with its periods in date order; period `locked_by` is a UserRef or null. */
class FiscalYearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status,
            'periods' => $this->periods->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
                'status' => $p->status,
                'locked_at' => $p->locked_at?->toIso8601String(),
                'locked_by' => $p->lockedBy ? ['id' => $p->lockedBy->id, 'name' => $p->lockedBy->name] : null,
                'lock_note' => $p->lock_note,
            ])->values()->all(),
        ];
    }
}
