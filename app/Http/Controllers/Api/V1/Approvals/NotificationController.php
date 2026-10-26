<?php

namespace App\Http\Controllers\Api\V1\Approvals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\NotificationListRequest;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** /notifications (L3 bell; FR-013; backlog P7.1-BE-05): the caller's own feed in this company, newest first. */
class NotificationController extends Controller
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function index(NotificationListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = $this->mine();
        if (isset($f['category'])) {
            $q->where('category', $f['category']);
        }
        if (isset($f['unread'])) {
            $f['unread'] === 'true' ? $q->whereNull('read_at') : $q->whereNotNull('read_at');
        }
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($n) => ['id' => $n->id, 'event_key' => $n->event_key, 'title' => $n->title, 'body' => $n->body,
                'category' => $n->category, 'link_type' => $n->link_type, 'link_id' => $n->link_id,
                'read_at' => $n->read_at ? Carbon::parse($n->read_at)->toIso8601String() : null, 'created_at' => Carbon::parse($n->created_at)->toIso8601String()])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function read(int $id): JsonResponse
    {
        $n = $this->mine()->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        if ($n->read_at === null) {
            DB::table('notifications')->where('id', $id)->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(null, 204);
    }

    public function readAll(): JsonResponse
    {
        $this->mine()->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(null, 204);
    }

    private function mine()
    {
        return DB::table('notifications')->where('company_id', $this->current->id())->where('user_id', auth()->id());
    }
}
