<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\BankingQueryRequest;
use App\Models\MoneyAccount;
use App\Queries\BankingQuery;
use App\Queries\LedgerEntriesQuery;
use App\Queries\PartyAccountQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;

/**
 * Cash and bank reads (G1, G11–G13; backlog P12.1-BE-01; route permission banking.view): the overview, every money
 * movement, and an account's book — the ledger statement of its ledger (opening, posted lines with a running balance,
 * closing; BR-005), this financial year to today by default.
 */
class BankingController extends Controller
{
    public function __construct(private readonly BankingQuery $banking, private readonly CurrentCompany $current) {}

    public function overview(BankingQueryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->banking->overview($this->current->id())]);
    }

    public function transactions(BankingQueryRequest $request): JsonResponse
    {
        $page = $this->banking->transactions($this->current->id(), $request->validated());

        return response()->json(['data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    /** `view` in / out lists only the money in or out; opening, totals and closing stay those of the whole range. */
    public function book(BankingQueryRequest $request, int $id, LedgerEntriesQuery $statements): JsonResponse
    {
        $account = MoneyAccount::findOrFail($id);
        $f = $request->validated();
        [$from, $to] = PartyAccountQuery::range($f['date_from'] ?? null, $f['date_to'] ?? null);
        $statement = $statements->statement((int) $account->ledger_id, $from, $to, null, (int) ($f['page'] ?? 1));
        $view = $f['view'] ?? 'all';
        if ($view !== 'all') {
            $statement['lines'] = array_values(array_filter($statement['lines'], fn ($l) => ! in_array((string) ($view === 'in' ? $l['debit'] : $l['credit']), ['0', '0.00', ''], true)));
        }

        return response()->json(['data' => $statement]);
    }
}
