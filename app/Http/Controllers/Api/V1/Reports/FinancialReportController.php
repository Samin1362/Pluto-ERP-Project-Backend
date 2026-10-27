<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\FinancialReportRequest;
use App\Queries\PartyAccountQuery;
use App\Queries\Reports\AgingQuery;
use App\Queries\Reports\BalanceSheetQuery;
use App\Queries\Reports\CashFlowQuery;
use App\Queries\Reports\GeneralLedgerQuery;
use App\Queries\Reports\ProfitAndLossQuery;
use App\Queries\Reports\TrialBalanceQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financial statements (H15–H19, J6, J7; 05 Reports; backlog P9.1): ReportData as JSON, or the file with
 * `format=xlsx|pdf|csv`, which also needs `reports.export`. Route permission: `reports.financial` (DEC-13).
 */
class FinancialReportController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions, private readonly ReportExporter $exporter) {}

    public function trialBalance(FinancialReportRequest $request, TrialBalanceQuery $query): Response
    {
        $f = $request->validated();

        return $this->respond($request, 'trial-balance', $query->report($this->current->id(), $f['as_of'] ?? $this->today(), $f['level'] ?? 'ledger',
            isset($f['branch_id']) ? (int) $f['branch_id'] : null));
    }

    public function generalLedger(FinancialReportRequest $request, GeneralLedgerQuery $query): Response
    {
        $f = $request->validated();
        [$from, $to] = PartyAccountQuery::range($f['date_from'] ?? null, $f['date_to'] ?? null);

        return $this->respond($request, 'general-ledger', $query->report($this->current->id(), $from, $to,
            isset($f['ledger_ids']) ? FinancialReportRequest::ids($f['ledger_ids']) : null, $f['group_by'] ?? 'ledger'));
    }

    public function profitAndLoss(FinancialReportRequest $request, ProfitAndLossQuery $query): Response
    {
        $f = $request->validated();
        [$from, $to] = PartyAccountQuery::range($f['date_from'] ?? null, $f['date_to'] ?? null);

        return $this->respond($request, 'profit-and-loss', $query->report($this->current->id(), $from, $to, ($f['compare'] ?? 'none') === 'prior',
            ($f['detail'] ?? 'ledgers') === 'ledgers', isset($f['branch_id']) ? (int) $f['branch_id'] : null));
    }

    public function balanceSheet(FinancialReportRequest $request, BalanceSheetQuery $query): Response
    {
        $f = $request->validated();

        return $this->respond($request, 'balance-sheet', $query->report($this->current->id(), $f['as_of'] ?? $this->today(), ($f['compare'] ?? 'none') === 'prior',
            ($f['detail'] ?? 'ledgers') === 'ledgers'));
    }

    public function cashFlow(FinancialReportRequest $request, CashFlowQuery $query): Response
    {
        $f = $request->validated();
        [$from, $to] = PartyAccountQuery::range($f['date_from'] ?? null, $f['date_to'] ?? null);

        return $this->respond($request, 'cash-flow', $query->report($this->current->id(), $from, $to));
    }

    public function receivablesAging(FinancialReportRequest $request, AgingQuery $query): Response
    {
        return $this->aging($request, $query, 'customer', 'receivables-aging');
    }

    public function payablesAging(FinancialReportRequest $request, AgingQuery $query): Response
    {
        return $this->aging($request, $query, 'supplier', 'payables-aging');
    }

    private function aging(FinancialReportRequest $request, AgingQuery $query, string $type, string $name): Response
    {
        $f = $request->validated();

        return $this->respond($request, $name, $query->report($this->current->id(), $type, $f['as_of'] ?? $this->today(), $f['q'] ?? null,
            in_array($f['overdue_only'] ?? 'false', ['true', '1'], true)));
    }

    private function respond(FinancialReportRequest $request, string $name, array $report): Response
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

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }
}
