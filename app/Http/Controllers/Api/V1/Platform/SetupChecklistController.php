<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Dashboard\SetupChecklistService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** GET /setup-checklist, POST /setup-checklist/hide (A1 dashboard; PEP-511). */
class SetupChecklistController extends Controller
{
    public function __construct(private readonly SetupChecklistService $checklist) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->checklist->get()]);
    }

    public function hide(): JsonResponse
    {
        return response()->json(['data' => $this->checklist->hide()]);
    }
}
