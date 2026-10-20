<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/** Backlog P0.2-BE-06: liveness and readiness probes. */
class HealthEndpointsTest extends TestCase
{
    public function test_health_answers_without_touching_dependencies(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_ready_when_database_and_redis_answer(): void
    {
        Redis::shouldReceive('connection')->andReturn(Mockery::mock(['ping' => true]));

        $this->getJson('/api/ready')->assertOk()->assertExactJson(['status' => 'ready', 'db' => 'ok', 'redis' => 'ok']);
    }

    public function test_not_ready_names_the_failing_dependency(): void
    {
        // Nothing listens on port 1: the Redis probe fails fast while the database still answers.
        config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1,
            'database.redis.default.timeout' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->getJson('/api/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready', 'db' => 'ok', 'redis' => 'fail']);
    }

    public function test_probes_are_not_rate_limited(): void
    {
        foreach (range(1, 30) as $_) {
            $this->getJson('/api/health')->assertOk();
        }
    }
}
