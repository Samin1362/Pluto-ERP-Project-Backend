<?php

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\AnnouncementService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformConsole\AnnouncementRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** /platform/announcements (K17; PEP-513). Platform admins only (middleware `platform`). */
class PlatformAnnouncementController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(): JsonResponse
    {
        $all = $this->announcements->all();

        return response()->json(['data' => $all, 'meta' => ['current_page' => 1, 'per_page' => max(1, count($all)), 'total' => count($all), 'last_page' => 1]]);
    }

    public function store(AnnouncementRequest $request): JsonResponse
    {
        $a = $this->announcements->create($request->validated());

        return response()->json(['data' => $a], 201)->setEtag((string) $a['version'], true);
    }

    public function update(AnnouncementRequest $request, int $id): JsonResponse
    {
        $a = $this->announcements->update($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => $a])->setEtag((string) $a['version'], true);
    }

    public function destroy(int $id): Response
    {
        $this->announcements->delete($id);

        return response()->noContent();
    }
}
