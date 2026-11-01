<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\StrictRequest;
use App\Queries\DashboardQuery;
use Illuminate\Validation\Rule;

/** Query of GET /dashboard and GET /dashboard/lenses/{lens} (backlog P14.1-BE-01/02): the period only. */
class DashboardRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['period'];
    }

    public function rules(): array
    {
        return ['period' => ['sometimes', Rule::in(DashboardQuery::PERIODS)]];
    }

    public function messages(): array
    {
        return ['period.in' => 'Choose this_month, this_quarter or this_year'];
    }
}
