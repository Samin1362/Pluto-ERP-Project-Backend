<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Platform\AnnouncementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** GET /announcements, POST /announcements/{id}/dismiss (S0 shell banner; PEP-513). Any signed-in person. */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): JsonResponse
    {
        $all = $this->announcements->current((int) $request->attributes->get('auth_user')->id);

        return response()->json(['data' => $all, 'meta' => ['current_page' => 1, 'per_page' => max(1, count($all)), 'total' => count($all), 'last_page' => 1]]);
    }

    public function dismiss(Request $request, int $id): Response
    {
        $this->announcements->dismiss($id, (int) $request->attributes->get('auth_user')->id);

        return response()->noContent();
    }
}
