<?php

namespace App\Http\Controllers\Api\V1\Parties;

use App\Domain\Parties\PaymentReminderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\PaymentReminderListRequest;
use App\Models\Party;
use Illuminate\Http\JsonResponse;

/** Payment reminders to a customer (PEP-516): send one now (statement attached) and the log of those sent. */
class PaymentReminderController extends Controller
{
    public function __construct(private readonly PaymentReminderService $reminders) {}

    public function store(int $id): JsonResponse
    {
        return response()->json(['data' => $this->reminders->send(Party::findOrFail($id), 'manual', (int) auth()->id())], 201);
    }

    public function index(PaymentReminderListRequest $request, int $id): JsonResponse
    {
        return response()->json($this->reminders->list(Party::findOrFail($id), (int) $request->validated('per_page', 25), (int) $request->validated('page', 1)));
    }
}
