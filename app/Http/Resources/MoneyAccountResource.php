<?php

namespace App\Http\Resources;

use App\Domain\Banking\MoneyAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * MoneyAccount (05 schemas, G3/G4). The account number is always masked (••1234). Without banking.view (`full` false;
 * the E7/F5 pickers for e.g. Sales staff) only id, name, type and the use-for flags are returned — no balance, numbers
 * or reconciliation data (docs 12 §3).
 */
class MoneyAccountResource extends JsonResource
{
    public function __construct($resource, private readonly bool $full = true)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $picker = ['id' => $a->id, 'account_type' => $a->account_type, 'name' => $a->name, 'use_for_receipts' => $a->use_for_receipts,
            'use_for_payments' => $a->use_for_payments, 'is_active' => $a->is_active];
        if (! $this->full) {
            return $picker;
        }

        return $picker + [
            'overdraft_limit' => $a->overdraft_limit,
            'bank_name' => $a->bank_name,
            'branch_name' => $a->branch_name,
            'account_name' => $a->account_name,
            'account_number_masked' => $a->maskedNumber(),
            'routing_number' => $a->routing_number,
            'currency_code' => $a->currency_code,
            'ledger_id' => $a->ledger_id,
            'ledger_code' => $a->ledger?->code ?? '',
            'book_balance' => (string) MoneyAccountService::bookBalance($a->ledger_id),
            'custodian' => $a->custodian ? ['id' => $a->custodian->id, 'name' => $a->custodian->name] : null,
            'show_on_invoices' => $a->show_on_invoices,
            'last_reconciled_on' => $a->last_reconciled_on?->toDateString(),
            'unmatched_lines' => DB::table('bank_statement_lines')->where('money_account_id', $a->id)->where('status', 'unmatched')->count(),
            'version' => (int) $a->lock_version,
        ];
    }
}
