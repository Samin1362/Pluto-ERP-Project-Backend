<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/close (LcCloseRequest; BR-046). */
class LcCloseRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['override_reason', 'release_margin_to_account_id'];
    }

    public function rules(): array
    {
        return [
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'release_margin_to_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }
}
