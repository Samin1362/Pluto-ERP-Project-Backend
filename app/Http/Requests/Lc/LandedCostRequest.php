<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/landed-cost[/preview] (LandedCostRequest; BR-045). */
class LandedCostRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['purchase_bill_id', 'allocation_date', 'method', 'charge_ids'];
    }

    public function rules(): array
    {
        return [
            'purchase_bill_id' => ['required', 'integer', Rule::exists('purchase_bills', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'allocation_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['sometimes', Rule::in(['value', 'quantity'])],
            'charge_ids' => ['sometimes', 'array'],
            'charge_ids.*' => ['integer', 'distinct'],
        ];
    }
}
