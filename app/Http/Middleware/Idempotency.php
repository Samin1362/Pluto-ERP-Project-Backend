<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key (BR-050, 05 §1): same key + same body within 24 h replays the stored response; same key with a
 * different body is 422 idempotency_key_reused; a replay while the first request runs is 409 idempotency_in_progress.
 * Only successful responses are stored, so a corrected retry after a 422 is processed normally.
 */
class Idempotency
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }
        if (! Str::isUuid($key)) {
            throw new ApiProblem('validation_failed', errors: ['Idempotency-Key' => ['Use a UUID for the Idempotency-Key header.']]);
        }
        $userId = $request->attributes->get('auth_user')->id;
        $companyId = $this->current->has() ? $this->current->id() : null;
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $scope = fn () => IdempotencyKey::where('user_id', $userId)->where('idempotency_key', $key)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'));

        try {
            $row = IdempotencyKey::create([
                'company_id' => $companyId, 'user_id' => $userId, 'idempotency_key' => $key, 'method' => $request->method(),
                'path' => substr($request->path(), 0, 255), 'request_hash' => $hash,
                'locked_until' => now()->addSeconds(30), 'expires_at' => now()->addDay(),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062 && ! str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
            $row = $scope()->firstOrFail();
            if ($row->request_hash !== $hash) {
                throw new ApiProblem('idempotency_key_reused');
            }
            if ($row->response_status !== null && $row->expires_at->isFuture()) {
                return (new JsonResponse($row->response_body, $row->response_status))->header('Idempotency-Replayed', 'true');
            }
            if ($row->locked_until !== null && $row->locked_until->isFuture()) {
                throw new ApiProblem('idempotency_in_progress');
            }
            $row->forceFill(['locked_until' => now()->addSeconds(30), 'expires_at' => now()->addDay()])->save();
        }

        $response = $next($request);
        if ($response->getStatusCode() < 300 && $response instanceof JsonResponse) {
            $row->forceFill(['response_status' => $response->getStatusCode(), 'response_body' => $response->getData(true), 'locked_until' => null])->save();
        } else {
            $row->delete();
        }

        return $response;
    }
}
