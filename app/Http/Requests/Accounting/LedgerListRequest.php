<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;
use App\Queries\LedgerQuery;
use Illuminate\Validation\Rule;

/** GET /ledgers query (05 getLedgers): picker purposes, chart filters, paging; unknown parameters are a 422. */
class LedgerListRequest extends StrictRequest
{
    public const SORTS = ['code', '-code', 'name', '-name'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'nature', 'kind', 'cash_vouchers', 'active', 'purpose', 'account_group_id'];
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'nature' => ['sometimes', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'kind' => ['sometimes', Rule::in(['general', 'party', 'bank', 'cash', 'inventory', 'tax', 'system'])],
            'cash_vouchers' => ['sometimes', Rule::in(['true', 'false'])],
            'active' => ['sometimes', Rule::in(['true', 'false', 'all'])],
            'purpose' => ['sometimes', Rule::in(array_merge(array_keys(LedgerQuery::PURPOSES), ['any']))],
            'account_group_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
