<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Accounting\AccountGroupService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountGroupRequest;
use App\Http\Requests\Accounting\AccountTreeRequest;
use App\Models\AccountGroup;
use App\Queries\AccountTreeQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /account-groups (H2/H3; backlog P7.1-BE-01): the chart of accounts tree with balances, group details, create and edit. */
class AccountGroupController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly AccountGroupService $groups, private readonly AccountTreeQuery $query, private readonly CurrentCompany $current) {}

    public function index(AccountTreeRequest $request): JsonResponse
    {
        $f = $request->validated();

        return response()->json(['data' => $this->query->tree($this->current->id(), $f['as_of'] ?? now()->toDateString(),
            ($f['with_ledgers'] ?? 'true') === 'true', ($f['zero'] ?? 'true') === 'true')]);
    }

    public function store(AccountGroupRequest $request): JsonResponse
    {
        $group = $this->groups->create($request->validated());

        return $this->respond($group->id, 201)->header('Location', url("/api/v1/account-groups/{$group->id}"));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond(AccountGroup::findOrFail($id)->id);
    }

    public function update(AccountGroupRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);
        $this->groups->update(AccountGroup::findOrFail($id), $request->validated(), $version);

        return $this->respond($id);
    }

    private function respond(int $id, int $status = 200): JsonResponse
    {
        $group = $this->query->group($this->current->id(), $id, now()->toDateString());

        return response()->json(['data' => $group], $status)->setEtag((string) $group['version'], true);
    }
}
