<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ledger (05 schemas). Balance fields only for callers with accounting.view (R-26); balance and opening balance are
 * absolute decimal strings with a dr|cr side, like parties.
 */
class LedgerResource extends JsonResource
{
    public function __construct($resource, private readonly array $groupPaths, private readonly bool $withBalances, private readonly ?array $fySummary = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_group_id' => $this->account_group_id,
            'group_path' => $this->groupPaths[$this->account_group_id] ?? [],
            'nature' => $this->group_nature,
            'normal_balance' => $this->group_normal_balance,
            'ledger_kind' => $this->ledger_kind,
            'description' => $this->description,
            'track_bills' => (bool) $this->track_bills,
            'show_in_cash_vouchers' => (bool) $this->show_in_cash_vouchers,
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            'has_entries' => (bool) $this->getAttribute('has_entries'),
        ];
        if ($this->withBalances) {
            foreach (['opening' => 'opening_net', 'balance' => 'balance_net'] as $field => $column) {
                $net = Decimal::fixed($this->getAttribute($column));
                $negative = str_starts_with($net, '-');
                $data[$field === 'opening' ? 'opening_balance' : 'balance'] = $negative ? substr($net, 1) : $net;
                $data[$field === 'opening' ? 'opening_side' : 'balance_side'] = $negative ? 'cr' : 'dr';
            }
        }
        if ($this->fySummary !== null) {
            $data['fy_summary'] = $this->fySummary;
        }
        $data['version'] = (int) $this->lock_version;

        return $data;
    }
}
