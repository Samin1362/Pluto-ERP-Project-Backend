<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use App\Support\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Query of the inventory reports (05 getReportsInventorySummary|LowStock|Valuation; D12–D14; backlog P11.1-BE-04).
 * Summary takes none; low stock: warehouse, status (all/out/low), group_by_supplier; valuation: as_of (default today),
 * warehouse, level (items/groups), format.
 */
class InventoryReportRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return match ($this->report()) {
            'valuation' => ['as_of', 'warehouse_id', 'level', 'format'],
            'low-stock' => ['warehouse_id', 'status', 'group_by_supplier'],
            default => [],
        };
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $c)],
            'level' => ['sometimes', Rule::in(['items', 'groups'])],
            'status' => ['sometimes', Rule::in(['all', 'out', 'low'])],
            'group_by_supplier' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'format' => ['sometimes', Rule::in(['json', ...ReportExporter::FORMATS])],
        ];
    }

    private function report(): string
    {
        return (string) str((string) $this->route()?->getName())->afterLast('.');
    }
}
