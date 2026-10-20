<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /me: current user and memberships; permissions only when X-Company-Id is sent. */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => (new MeResource($request->attributes->get('auth_user')))->resolve($request)]);
    }
}
