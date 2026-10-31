<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\InventoryReportRequest;
use App\Queries\Reports\InventoryReportQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory reports (D12–D14; backlog P11.1-BE-04): summary and low stock need inventory.view (values only with
 * inventory.view_costs, BR-052); valuation needs inventory.view_costs and can be exported (format=xlsx|pdf|csv, which
 * also needs reports.export).
 */
class InventoryReportController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
        private readonly ReportExporter $exporter,
        private readonly InventoryReportQuery $reports,
    ) {}

    public function summary(InventoryReportRequest $request): Response
    {
        return response()->json(['data' => $this->reports->summary($this->current->id(), $this->costs())]);
    }

    public function lowStock(InventoryReportRequest $request): Response
    {
        $f = $request->validated();
        $f['group_by_supplier'] = in_array($f['group_by_supplier'] ?? 'false', ['true', '1'], true);
        $f['warehouse_id'] = isset($f['warehouse_id']) ? (int) $f['warehouse_id'] : null;

        return response()->json(['data' => $this->reports->lowStock($this->current->id(), $f, $this->costs())]);
    }

    public function valuation(InventoryReportRequest $request): Response
    {
        $f = $request->validated();
        $f['as_of'] ??= CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $f['warehouse_id'] = isset($f['warehouse_id']) ? (int) $f['warehouse_id'] : null;
        $report = $this->reports->valuation($this->current->id(), $f);
        $format = $f['format'] ?? 'json';
        if ($format === 'json') {
            return response()->json(['data' => $report]);
        }
        if (! $this->permissions->allows($this->current->membership(), 'reports.export')) {
            throw new ApiProblem('permission_denied', 'Your role does not include reports.export.');
        }

        return $this->exporter->download($report, $format, 'stock-valuation');
    }

    private function costs(): bool
    {
        return $this->permissions->allows($this->current->membership(), 'inventory.view_costs');
    }
}
