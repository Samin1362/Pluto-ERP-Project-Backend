<?php

namespace App\Http\Controllers\Api\V1\Parties;

use App\Http\Controllers\Controller;
use App\Models\PartyGroup;
use App\Models\PaymentTerm;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /payment-terms and GET /party-groups: small, unpaginated-in-practice lookups returned in the list envelope. */
class LookupController extends Controller
{
    public function paymentTerms(Request $request): JsonResponse
    {
        $this->noUnknownParams($request, []);
        $rows = PaymentTerm::orderBy('days')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'days' => $t->days, 'is_default' => $t->is_default]);

        return $this->page($rows->all());
    }

    public function partyGroups(Request $request): JsonResponse
    {
        $this->noUnknownParams($request, ['type']);
        $type = $request->query('type');
        if ($type !== null && ! in_array($type, ['customer', 'supplier'], true)) {
            throw new ApiProblem('validation_failed', errors: ['type' => ['Use customer or supplier']]);
        }
        $rows = PartyGroup::when($type, fn ($q) => $q->where('party_type', $type))->orderBy('name')->get()
            ->map(fn ($g) => ['id' => $g->id, 'party_type' => $g->party_type, 'name' => $g->name]);

        return $this->page($rows->all());
    }

    private function page(array $rows): JsonResponse
    {
        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    private function noUnknownParams(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->query()), $allowed);
        if ($unknown) {
            throw new ApiProblem('validation_failed', errors: array_fill_keys($unknown, ['This parameter is not part of the API.']));
        }
    }
}
