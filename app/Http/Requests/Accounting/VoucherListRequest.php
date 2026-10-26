<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /vouchers query (05 getVouchers): the voucher list and day book. */
class VoucherListRequest extends StrictRequest
{
    public const SORTS = ['date', '-date', 'number', '-number', 'amount', '-amount'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'entry_type', 'status', 'ledger_id'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'entry_type' => ['sometimes', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'posted', 'reversed'])],
            'ledger_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
