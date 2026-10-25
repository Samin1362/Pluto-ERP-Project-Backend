<?php

namespace App\Http\Controllers\Api\V1\Settlements;

use App\Domain\Access\PermissionResolver;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlements\AllocationRequest;
use App\Models\Party;
use App\Queries\OpenDocumentsQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Allocations (R-08; backlog P6.1-BE-02): apply an open credit to an invoice or bill later, undo it, and the party's
 * open documents and credits. Permission depends on the party (R-09): a customer needs sales.create (apply) or
 * sales.edit (remove), a supplier purchases.create / purchases.edit.
 */
class AllocationController extends Controller
{
    public function __construct(private readonly AllocationService $allocations, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function store(AllocationRequest $request): JsonResponse
    {
        $f = $request->validated();
        $line = $this->allocations->settlingLine((int) $f['journal_line_id']);
        $this->authorizeFor(Party::withTrashed()->where('ledger_id', $line->ledger_id)->firstOrFail(), 'create');
        $id = $this->allocations->allocate($line->id, $f['document_type'], (int) $f['document_id'], Money::of($f['amount']), $f['allocated_on'] ?? null);

        return response()->json(['data' => AllocationService::row($id)], 201);
    }

    public function destroy(int $id): Response
    {
        $a = DB::table('bill_allocations')->where('company_id', $this->current->id())->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        $this->authorizeFor(Party::withTrashed()->where('ledger_id', DB::table('journal_lines')->where('id', $a->journal_line_id)->value('ledger_id'))->firstOrFail(), 'edit');
        $this->allocations->remove($id);

        return response()->noContent();
    }

    public function openDocuments(Request $request, int $id, OpenDocumentsQuery $query): JsonResponse
    {
        if ($request->query()) {
            throw new ApiProblem('validation_failed', errors: array_fill_keys(array_keys($request->query()), ['This parameter is not part of the API.']));
        }

        return response()->json(['data' => $query->for(Party::findOrFail($id))]);
    }

    private function authorizeFor(Party $party, string $action): void
    {
        $permission = ($party->party_type === 'customer' ? 'sales.' : 'purchases.').$action;
        if (! $this->permissions->allows($this->current->membership(), $permission)) {
            throw new ApiProblem('permission_denied', "You need {$permission} to change allocations of a {$party->party_type}.", extra: ['required_permission' => $permission]);
        }
    }
}
