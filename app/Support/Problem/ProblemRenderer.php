<?php

namespace App\Support\Problem;

use App\Http\Middleware\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps every exception on /api/* to RFC 9457 Problem Details with the catalogue `code` (03 §7, 05 §3).
 * Unexpected errors expose only the request id; details go to the log.
 */
final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $detail, $errors, $extra, $headers, $status] = self::classify($e);
        $body = [
            'type' => 'https://docs.pluto.example/errors/'.$code,
            'title' => ErrorCatalogue::title($code),
            'status' => $status,
            'code' => $code,
        ];
        if ($detail !== null && $detail !== '') {
            $body['detail'] = $detail;
        }
        $body['instance'] = '/'.ltrim($request->path(), '/');
        $body['request_id'] = RequestId::current($request);
        if ($errors) {
            $body['errors'] = $errors;
        }
        $body += $extra;

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0:string,1:?string,2:array,3:array,4:array,5:int} */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiProblem => [$e->errorCode, $e->detail, $e->errors, $e->extra, $e->headers, $e->status],
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => ['unauthenticated', null, [], [], [], 401],
            $e instanceof AuthorizationException => ['permission_denied', null, [], [], [], 403],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ['not_found', null, [], [], [], 404],
            $e instanceof ThrottleRequestsException => ['rate_limited', null, [], [], self::retryAfter($e), 429],
            $e instanceof MethodNotAllowedHttpException => ['not_found', 'This method is not supported on this resource', [], [], [], 404],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => ['company_maintenance', null, [], [], $e->getHeaders(), 503],
            default => self::unexpected($e),
        };
    }

    private static function validation(ValidationException $e): array
    {
        $errors = $e->errors();
        // BR-063: a mobile-number format failure on its own carries its own code (05 error catalogue).
        $code = (array_keys($errors) === ['mobile'] && ($e->validator->failed()['mobile']['Regex'] ?? null) !== null)
            ? 'invalid_mobile' : 'validation_failed';
        $n = array_sum(array_map('count', $errors));

        return [$code, $n === 1 ? 'Fix 1 field to continue' : "Fix {$n} fields to continue", $errors, [], [], 422];
    }

    private static function retryAfter(ThrottleRequestsException $e): array
    {
        $h = $e->getHeaders();

        return isset($h['Retry-After']) ? ['Retry-After' => (string) $h['Retry-After']] : [];
    }

    private static function unexpected(Throwable $e): array
    {
        report($e);

        return ['server_error', null, [], [], [], 500];
    }
}
