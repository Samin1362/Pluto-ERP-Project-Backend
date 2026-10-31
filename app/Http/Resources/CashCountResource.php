<?php

namespace App\Http\Resources;

use App\Domain\Approvals\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CashCount (05 schemas; G2). Status: recorded (no difference, or its adjustment still waits for approval), adjusted
 * (the adjustment posted), reversed. Also the account, the denominations and the adjustment's number, status and
 * approval request, so G2 can show where it stands.
 */
class CashCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $entry = $c->entry;
        $approval = $entry ? ApprovalService::latestFor($entry) : null;

        return [
            'id' => $c->id, 'money_account_id' => $c->money_account_id, 'money_account_name' => $c->account?->name,
            'counted_at' => $c->counted_at->toIso8601String(), 'counted_by' => ['id' => $c->counted_by, 'name' => $c->counter?->name],
            'book_balance' => (string) $c->book_balance, 'counted_total' => (string) $c->counted_total, 'difference' => (string) $c->difference, 'note' => $c->note,
            'status' => $c->status === 'recorded' && $entry?->status === 'posted' ? 'adjusted' : $c->status,
            'adjustment_entry_id' => $c->adjustment_entry_id, 'adjustment_number' => $entry?->voucher_number, 'adjustment_status' => $entry?->status,
            'approval_request_id' => $approval['id'] ?? null,
            'denominations' => $c->denominations->map(fn ($d) => ['denomination' => (string) $d->denomination, 'count' => (int) $d->count, 'value' => (string) $d->value])->all(),
        ];
    }
}
