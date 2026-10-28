<?php

namespace App\Http\Requests\Reports;

use App\Domain\Purchases\PurchaseReturnService;
use App\Domain\Sales\SalesReturnService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use App\Support\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Query of the trade reports and overviews (05 getReportsSales…, getSalesOverview, getPurchasesOverview; backlog
 * P10.5-BE-01). Reports: date range (default this month), group_by (customer/supplier, category, month — no salesperson,
 * C15), branch, party, return reason, format. Overviews: period. Ids must be the company's.
 */
class TradeReportRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return $this->overview() ? ['period'] : ['date_from', 'date_to', 'group_by', 'branch_id', 'party_id', 'reason', 'format'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $purchases = str_contains((string) $this->route()?->getName(), 'purchase');

        return [
            'period' => ['sometimes', Rule::in(['this_month', 'last_month', 'this_quarter', 'this_year'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'group_by' => ['sometimes', Rule::in([$purchases ? 'supplier' : 'customer', 'category', 'month'])],
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('company_id', $c)],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', $purchases ? 'supplier' : 'customer')],
            'reason' => ['sometimes', Rule::in($purchases ? PurchaseReturnService::REASONS : SalesReturnService::REASONS)],
            'format' => ['sometimes', Rule::in(['json', ...ReportExporter::FORMATS])],
        ];
    }

    public function messages(): array
    {
        return ['group_by.in' => 'Group by party, category or month', 'date_to.after_or_equal' => 'The end date cannot be before the start date'];
    }

    private function overview(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.overview');
    }
}
