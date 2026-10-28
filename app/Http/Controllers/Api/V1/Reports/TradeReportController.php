<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\TradeReportRequest;
use App\Queries\Reports\TradeOverviewQuery;
use App\Queries\Reports\TradeReportQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trade reports (E11, E12, F9, F10; route permission reports.view) and the sales and purchase overviews (E1, F1;
 * sales.view / purchases.view): ReportData as JSON, or a file with format=xlsx|pdf|csv (also needs reports.export).
 * Gross margin and outstanding balances need reports.financial (DEC-13, C7); without it they are left out.
 */
class TradeReportController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
        private readonly ReportExporter $exporter,
        private readonly TradeReportQuery $reports,
        private readonly TradeOverviewQuery $overviews,
    ) {}

    public function sales(TradeReportRequest $request): Response
    {
        return $this->respond($request, 'sales', $this->reports->sales($this->current->id(), $this->filters($request), $this->financial()));
    }

    public function salesReturns(TradeReportRequest $request): Response
    {
        return $this->respond($request, 'sales-returns', $this->reports->returns($this->current->id(), 'sales', $this->filters($request)));
    }

    public function purchases(TradeReportRequest $request): Response
    {
        return $this->respond($request, 'purchases', $this->reports->purchases($this->current->id(), $this->filters($request)));
    }

    public function purchaseReturns(TradeReportRequest $request): Response
    {
        return $this->respond($request, 'purchase-returns', $this->reports->returns($this->current->id(), 'purchases', $this->filters($request)));
    }

    public function salesOverview(TradeReportRequest $request): Response
    {
        return response()->json(['data' => $this->overviews->sales($this->current->id(), $request->validated('period') ?? 'this_month', $this->financial())]);
    }

    public function purchasesOverview(TradeReportRequest $request): Response
    {
        return response()->json(['data' => $this->overviews->purchases($this->current->id(), $request->validated('period') ?? 'this_month', $this->financial())]);
    }

    /** Date range defaults to this month to date; ids as integers. */
    private function filters(TradeReportRequest $request): array
    {
        $f = $request->validated();
        $today = CarbonImmutable::now('Asia/Dhaka');
        $f['date_from'] ??= $today->startOfMonth()->toDateString();
        $f['date_to'] ??= $today->toDateString();
        foreach (['branch_id', 'party_id'] as $k) {
            $f[$k] = isset($f[$k]) ? (int) $f[$k] : null;
        }

        return $f;
    }

    private function financial(): bool
    {
        return $this->permissions->allows($this->current->membership(), 'reports.financial');
    }

    private function respond(TradeReportRequest $request, string $name, array $report): Response
    {
        $format = $request->validated('format') ?? 'json';
        if ($format === 'json') {
            return response()->json(['data' => $report]);
        }
        if (! $this->permissions->allows($this->current->membership(), 'reports.export')) {
            throw new ApiProblem('permission_denied', 'Your role does not include reports.export.');
        }

        return $this->exporter->download($report, $format, $name);
    }
}
