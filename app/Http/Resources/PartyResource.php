<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Party schema (05 §10). Balances come from posted journal lines (attributes set by the query):
 * balance is absolute with balance_side dr|cr; money is always a decimal string.
 */
class PartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $net = Decimal::fixed($p->getAttribute('balance_net'));
        $negative = str_starts_with($net, '-');

        return [
            'id' => $p->id,
            'party_type' => $p->party_type,
            'code' => $p->code,
            'name' => $p->name,
            'category' => $p->category,
            'group' => $p->group ? ['id' => $p->group->id, 'party_type' => $p->group->party_type, 'name' => $p->group->name] : null,
            'contact_person' => $p->contact_person,
            'mobile' => $p->mobile,
            'email' => $p->email,
            'alt_phone' => $p->alt_phone,
            'address' => $p->address,
            'district' => $p->district,
            'area' => $p->area,
            'country_code' => $p->country_code,
            'default_currency_code' => $p->default_currency_code,
            'payment_term' => $p->paymentTerm ? ['id' => $p->paymentTerm->id, 'name' => $p->paymentTerm->name,
                'days' => $p->paymentTerm->days, 'is_default' => $p->paymentTerm->is_default] : null,
            'credit_limit' => $p->credit_limit,
            'price_tier' => $p->price_tier,
            'ledger_id' => $p->ledger_id,
            'ledger_code' => $p->ledger?->code,
            'tax_registration_no' => $p->tax_registration_no,
            'port_of_loading' => $p->port_of_loading,
            'default_purchase_ledger_id' => $p->default_purchase_ledger_id,
            'port_of_loading' => $p->port_of_loading,
            'status' => $p->status,
            'balance' => $negative ? substr($net, 1) : $net,
            'balance_side' => $negative ? 'cr' : 'dr',
            'overdue' => Decimal::fixed($p->getAttribute('overdue_amount')),
            'opening_balance' => Decimal::fixed($p->getAttribute('opening_amount')),
            'opening_locked' => (bool) ($p->getAttribute('has_entries') ?? false),
            'bank_accounts' => $p->relationLoaded('bankAccounts') ? $p->bankAccounts->map(fn ($b) => [
                'id' => $b->id, 'bank_name' => $b->bank_name, 'branch' => $b->branch, 'account_name' => $b->account_name,
                'account_number' => $b->account_number, 'routing_number' => $b->routing_number, 'swift_code' => $b->swift_code,
                'is_default' => $b->is_default])->values()->all() : [],
            'notes' => $p->notes,
            'version' => (int) $p->lock_version,
        ];
    }
}
