<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use App\Support\Reports\ReportExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Query of the financial statements (05 getReports…; backlog P9.1): each report allows its own parameters (named
 * by the route), plus `format`. Ledger and branch ids must belong to the current company (07 §7.4).
 */
class FinancialReportRequest extends StrictRequest
{
    private const FIELDS = [
        'reports.trial-balance' => ['as_of', 'level', 'branch_id'],
        'reports.profit-and-loss' => ['date_from', 'date_to', 'compare', 'detail', 'branch_id'],
        'reports.balance-sheet' => ['as_of', 'compare', 'detail'],
        'reports.cash-flow' => ['date_from', 'date_to'],
        'reports.general-ledger' => ['date_from', 'date_to', 'ledger_ids', 'group_by'],
        'reports.receivables-aging' => ['as_of', 'q', 'overdue_only'],
        'reports.payables-aging' => ['as_of', 'q', 'overdue_only'],
    ];

    protected function allowedFields(): array
    {
        return [...self::FIELDS[$this->route()->getName()], 'format'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'level' => ['sometimes', Rule::in(['ledger', 'group'])],
            'compare' => ['sometimes', Rule::in(['none', 'prior'])],
            'detail' => ['sometimes', Rule::in(['ledgers', 'totals'])],
            'group_by' => ['sometimes', Rule::in(['ledger', 'month'])],
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('company_id', $c)],
            'ledger_ids' => ['sometimes', 'string', 'max:2000', function (string $attr, mixed $v, \Closure $fail) use ($c) {
                $ids = self::ids((string) $v);
                if ($ids === null || count($ids) > 200) {
                    $fail('Give up to 200 ledger ids separated by commas');
                } elseif (DB::table('ledgers')->where('company_id', $c)->whereIn('id', $ids)->count() !== count($ids)) {
                    $fail('One of the ledgers does not exist');
                }
            }],
            'q' => ['sometimes', 'string', 'max:100'],
            'overdue_only' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'format' => ['sometimes', Rule::in(['json', ...ReportExporter::FORMATS])],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'The end date must be on or after the start date'];
    }

    /** @return list<int>|null the distinct ids of a comma-separated list, null when one is not a positive integer */
    public static function ids(string $list): ?array
    {
        $parts = array_map('trim', explode(',', $list));
        foreach ($parts as $p) {
            if (! ctype_digit($p) || (int) $p < 1) {
                return null;
            }
        }

        return array_values(array_unique(array_map('intval', $parts)));
    }
}
