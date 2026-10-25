<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Domain\Access\PermissionResolver;
use App\Domain\Banking\MoneyAccountService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\MoneyAccountListRequest;
use App\Http\Requests\Banking\MoneyAccountRequest;
use App\Http\Resources\MoneyAccountResource;
use App\Models\MoneyAccount;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /money-accounts (G3/G4; backlog P6.1-BE-01): the picker list for every member, create, show and edit. */
class MoneyAccountController extends Controller
{
    use ReadsIfMatch;

    private const ORDER = "FIELD(account_type, 'cash', 'bank', 'wallet', 'card', 'cash_credit')";

    public function __construct(private readonly MoneyAccountService $accounts, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** Active accounts first, cash before bank, then by name. `purpose` keeps active accounts switched on for receipts/payments. */
    public function index(MoneyAccountListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = MoneyAccount::with(['ledger', 'custodian'])->orderByDesc('is_active')->orderByRaw(self::ORDER)->orderBy('name');
        if (isset($f['type'])) {
            $q->where('account_type', $f['type']);
        }
        if (in_array($f['purpose'] ?? null, ['receipts', 'payments'], true)) {
            $q->where('is_active', true)->where($f['purpose'] === 'receipts' ? 'use_for_receipts' : 'use_for_payments', true);
        }
        $rows = $q->get();
        $full = $this->permissions->allows($this->current->membership(), 'banking.view');

        return response()->json([
            'data' => $rows->map(fn (MoneyAccount $a) => (new MoneyAccountResource($a, $full))->resolve($request))->all(),
            'meta' => ['current_page' => 1, 'per_page' => max($rows->count(), 1), 'total' => $rows->count(), 'last_page' => 1],
        ]);
    }

    public function store(MoneyAccountRequest $request): JsonResponse
    {
        return $this->respond($request, $this->accounts->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, MoneyAccount::findOrFail($id));
    }

    public function update(MoneyAccountRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->accounts->update(MoneyAccount::findOrFail($id), $request->validated(), $version));
    }

    private function respond(Request $request, MoneyAccount $account, int $status = 200): JsonResponse
    {
        $account->loadMissing(['ledger', 'custodian']);

        return response()->json(['data' => (new MoneyAccountResource($account))->resolve($request)], $status)->setEtag((string) $account->lock_version, true);
    }
}
