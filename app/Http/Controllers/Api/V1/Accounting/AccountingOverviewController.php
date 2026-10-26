<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Queries\AccountingOverviewQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /accounting/overview (H1; backlog P7.1-BE-03): tiles, books-health checks, recent vouchers. No query parameters. */
class AccountingOverviewController extends Controller
{
    public function __construct(private readonly AccountingOverviewQuery $query, private readonly CurrentCompany $current) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->query() !== []) {
            throw new ApiProblem('validation_failed', errors: array_map(fn () => ['This field is not part of the API.'], $request->query()));
        }

        return response()->json(['data' => $this->query->overview($this->current->id())]);
    }
}
