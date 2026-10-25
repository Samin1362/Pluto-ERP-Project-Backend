<?php

namespace App\Http\Controllers\Api\V1\Parties;

use App\Domain\Parties\PartyStatementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\PartyAccountRequest;
use App\Http\Requests\Parties\StatementSendRequest;
use App\Models\Party;
use App\Queries\PartyAccountQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Party accounts (B5–B8 / C5–C8; backlog P6.1-BE-05): ledger with running balance, transactions, outstanding/aging,
 * statement (JSON, PDF) and statements by e-mail. All read posted entries only.
 */
class PartyAccountController extends Controller
{
    public function __construct(private readonly PartyAccountQuery $accounts, private readonly PartyStatementService $statements) {}

    public function ledger(PartyAccountRequest $request, int $id): JsonResponse
    {
        [$from, $to] = PartyAccountQuery::range($request->validated('date_from'), $request->validated('date_to'));

        return response()->json(['data' => $this->accounts->ledger(Party::findOrFail($id), $from, $to)]);
    }

    public function transactions(PartyAccountRequest $request, int $id): JsonResponse
    {
        $f = $request->validated();
        $all = $this->accounts->transactions(Party::findOrFail($id), $f);
        $per = (int) ($f['per_page'] ?? 25);
        $page = (int) ($f['page'] ?? 1);

        return response()->json([
            'data' => array_slice($all, ($page - 1) * $per, $per),
            'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => count($all), 'last_page' => max(1, (int) ceil(count($all) / $per))],
        ]);
    }

    public function outstanding(PartyAccountRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->accounts->outstanding($request->validated())]);
    }

    public function statement(PartyAccountRequest $request, int $id): JsonResponse
    {
        [$from, $to] = PartyAccountQuery::range($request->validated('date_from'), $request->validated('date_to'));

        return response()->json(['data' => $this->statements->build(Party::findOrFail($id), $from, $to, in_array($request->validated('aging'), ['true', '1'], true))]);
    }

    public function statementPdf(PartyAccountRequest $request, int $id): Response
    {
        $party = Party::findOrFail($id);
        [$from, $to] = PartyAccountQuery::range($request->validated('date_from'), $request->validated('date_to'));

        return response($this->statements->pdf($party, $from, $to, in_array($request->validated('aging'), ['true', '1'], true)), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"statement-{$party->code}.pdf\""]);
    }

    /** POST /parties/statements/send: queued e-mails; the ExportJob records who was sent and who has no e-mail. */
    public function send(StatementSendRequest $request): JsonResponse
    {
        $f = $request->validated();

        return response()->json(['data' => $this->statements->send(array_map('intval', $f['party_ids']), $f['date_from'], $f['date_to'], (bool) ($f['include_aging'] ?? false))], 202);
    }
}
