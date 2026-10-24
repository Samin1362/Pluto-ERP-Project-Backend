<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** TaxRule (05 schemas): a user-entered rate (BR-036) and the ledger it posts to. */
class TaxRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rate' => $this->rate,
            'applies_to' => $this->applies_to,
            'ledger_id' => $this->ledger_id,
            'ledger_name' => $this->ledger?->name,
            'is_recoverable' => (bool) $this->is_recoverable,
            'is_active' => (bool) $this->is_active,
            'version' => (int) $this->lock_version,
        ];
    }
}
