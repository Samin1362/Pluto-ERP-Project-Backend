<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and readiness for scripts, Docker and monitoring (backlog P0.2-BE-06, roadmap P0).
 * Outside /api/v1, no authentication, no rate limit, and no version or error details in the body.
 */
class HealthController extends Controller
{
    /** GET /api/health: the PHP app answers. Touches no dependency. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }

    /** GET /api/ready: the database and Redis answer too; 503 names the failing part. */
    public function ready(): JsonResponse
    {
        $checks = [
            'db' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];
        $ready = ! in_array('fail', $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'not_ready'] + $checks, $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
