<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * Query parameters of the income and expense reads (05: ie-categories, ie-entries, recurring-templates,
 * income-expenses/overview, reports/income, reports/expenses; backlog P13.1-BE-02..05). Unknown parameters: 422.
 */
class IncomeExpenseQueryRequest extends StrictRequest
{
    private const PERIODS = ['this_month', 'last_month', 'this_quarter', 'this_year'];

    protected function allowedFields(): array
    {
        return match (true) {
            $this->is('api/v1/ie-entries') => ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'kind', 'category_id', 'missing_receipt'],
            $this->is('api/v1/recurring-templates') => ['page', 'per_page', 'kind', 'active'],
            $this->is('api/v1/reports/*') => ['date_from', 'date_to', 'group_by', 'branch_id', 'party_id', 'reason', 'format'],
            default => ['kind', 'period'],
        };
    }

    public function rules(): array
    {
        $company = app(CurrentCompany::class)->id();

        return [
            'kind' => ['sometimes', Rule::in($this->is('api/v1/recurring-templates') ? ['income', 'expense', 'journal'] : ['income', 'expense'])],
            'period' => ['sometimes', Rule::in(self::PERIODS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['date', '-date', 'amount', '-amount'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category_id' => ['sometimes', 'integer', Rule::exists('ie_categories', 'id')->where('company_id', $company)],
            'missing_receipt' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'active' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'group_by' => ['sometimes', Rule::in(['month', 'quarter'])],
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('company_id', $company)],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $company)],
            'reason' => ['sometimes', 'string', 'max:60'],
            'format' => ['sometimes', Rule::in(['json', 'xlsx', 'pdf', 'csv'])],
        ];
    }
}
