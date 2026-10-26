<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Accounting\AccountMappingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountMappingsRequest;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /account-mappings (K16 Accounting defaults; backlog P7.1-BE-08). */
class AccountMappingController extends Controller
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->query() !== []) {
            throw new ApiProblem('validation_failed', errors: array_map(fn () => ['This field is not part of the API.'], $request->query()));
        }

        return $this->respond();
    }

    public function update(AccountMappingsRequest $request): JsonResponse
    {
        $this->mappings->save($request->validated()['mappings']);

        return $this->respond();
    }

    private function respond(): JsonResponse
    {
        return response()->json(['data' => $this->mappings->list()])->setEtag($this->mappings->version(), true);
    }
}
