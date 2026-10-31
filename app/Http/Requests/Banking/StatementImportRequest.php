<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** StatementImport (05 postBankStatementImports; G5, G10; backlog P12.1-BE-04): multipart file, the account, an optional column mapping. */
class StatementImportRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['file', 'money_account_id', 'mapping'];
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'mapping' => ['sometimes', 'nullable', 'array'],
            'mapping.*' => ['string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose the statement file (CSV or XLSX)', 'money_account_id.required' => 'Choose the bank account'];
    }
}
