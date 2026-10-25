<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Models\MoneyAccount;
use Illuminate\Validation\Rule;

/**
 * GET /money-accounts query (05 getMoneyAccounts; backlog P6.1-BE-01): `type` (account type) and `purpose` — receipts
 * or payments: active accounts switched on for that use (the E7/F5 pickers). The contract's purpose enum repeats the
 * ledger picker's values (contract gap, noted on PEP-165); those are accepted and mean "any".
 */
class MoneyAccountListRequest extends StrictRequest
{
    public const PURPOSES = ['receipts', 'payments', 'any', 'income_for_product', 'expense_for_product', 'purchase_for_supplier', 'tax', 'category'];

    protected function allowedFields(): array
    {
        return ['type', 'purpose'];
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(MoneyAccount::TYPES)],
            'purpose' => ['sometimes', Rule::in(self::PURPOSES)],
        ];
    }
}
