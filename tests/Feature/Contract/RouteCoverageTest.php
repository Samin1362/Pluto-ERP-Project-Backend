<?php

namespace Tests\Feature\Contract;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Support\CurrentCompany;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Contract and permission coverage generated from docs/openapi.yaml (backlog P17.1-BE-04):
 * - TC-NFR-008: every /api/v1 route is in the contract and every contract operation is implemented;
 * - TC-AUTH-008: each operation's x-permission is the route's perm: middleware, and a member whose role has no
 *   permissions is refused with 403 permission_denied on every protected operation.
 * TC-NFR-004 (every mutation audited) runs inside every feature test: see Tests\Concerns\AuditCoverage.
 * TC-CON-006's schema validation is OpenApiConformanceTest.
 */
class RouteCoverageTest extends TestCase
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    /** x-permission values that are not a permission key. */
    private const NOT_A_PERMISSION = ['public', 'auth', 'auth.self'];

    private static ?array $doc = null;

    private static function doc(): array
    {
        return self::$doc ??= Yaml::parseFile(base_path('docs/openapi.yaml'));
    }

    private static function norm(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim(preg_replace('#^/?api/v1#', '', $path), '/'));
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> "METHOD /path" → [method, contract path, operation] */
    private static function operations(): array
    {
        $ops = [];
        foreach (self::doc()['paths'] as $path => $item) {
            foreach (self::VERBS as $verb) {
                if (isset($item[$verb])) {
                    $ops[strtoupper($verb).' '.self::norm($path)] = [strtoupper($verb), $path, $item[$verb]];
                }
            }
        }

        return $ops;
    }

    /** @return array<string, Route> "METHOD /path" → route */
    private static function routes(): array
    {
        $routes = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $m) {
                if (in_array(strtolower($m), self::VERBS, true)) {
                    $routes[$m.' '.self::norm($route->uri())] = $route;
                }
            }
        }

        return $routes;
    }

    public function test_t_c_n_f_r_008_every_route_is_in_the_contract_and_every_operation_is_implemented(): void
    {
        $ops = self::operations();
        $routes = self::routes();
        $this->assertGreaterThan(300, count($ops));
        $this->assertSame([], array_values(array_diff(array_keys($routes), array_keys($ops))), 'routes missing from docs/openapi.yaml');
        $this->assertSame([], array_values(array_diff(array_keys($ops), array_keys($routes))), 'contract operations with no route');
    }

    public function test_t_c_a_u_t_h_008_each_operation_checks_the_permission_the_contract_names(): void
    {
        $routes = self::routes();
        $wrong = [];
        foreach (self::operations() as $key => [, , $op]) {
            $perm = $op['x-permission'] ?? null;
            if (! is_string($perm) || in_array($perm, self::NOT_A_PERMISSION, true) || ($op['x-permission-mode'] ?? null) === 'contextual') {
                continue;
            }
            $middleware = $routes[$key]->gatherMiddleware();
            if (! in_array("perm:{$perm}", $middleware, true)) {
                $wrong[] = "{$key}: contract says {$perm}, route has ".(implode(' ', array_filter($middleware, fn ($m) => str_starts_with($m, 'perm:'))) ?: 'no perm: middleware');
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_t_c_a_u_t_h_008_a_member_without_permissions_is_refused_everywhere(): void
    {
        $company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $nobody = $this->makeUser('nobody@meghna.example');
        $current = app(CurrentCompany::class);
        $current->set(Company::findOrFail($company->id), null);
        $role = Role::create(['name' => 'No permissions', 'slug' => 'no-permissions', 'is_system' => false, 'payment_approval_limit' => '0.00', 'journal_approval_limit' => '0.00']);
        CompanyUser::create(['user_id' => $nobody->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $current->clear();
        $token = $this->login('nobody@meghna.example');
        config(['pluto.rate_limits.api_per_user' => 100000]); // ~300 calls in a few seconds, by design

        $routes = self::routes();
        $checked = 0;
        $leaks = [];
        foreach (self::operations() as $key => [$method, , $op]) {
            $perm = $op['x-permission'] ?? null;
            if (! is_string($perm) || in_array($perm, self::NOT_A_PERMISSION, true) || ($op['x-permission-mode'] ?? null) === 'contextual') {
                continue;
            }
            $route = $routes[$key];
            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => self::sample($route, $m[1]), $route->uri());
            $r = $this->api($method, $uri, $token, $company->id, [], ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => 'W/"1"']);
            $checked++;
            if ($r->status() !== 403 || $r->json('code') !== 'permission_denied') {
                $leaks[] = "{$key} ({$perm}) → {$r->status()} ".($r->json('code') ?? '');
            }
        }
        $this->assertGreaterThan(250, $checked);
        $this->assertSame([], $leaks);
    }

    /** A value that satisfies the route's parameter constraint (so the request reaches the permission check). */
    private static function sample(Route $route, string $param): string
    {
        $where = $route->wheres[$param] ?? null;
        if ($where === null || preg_match('/^\[0-9\]\+$|^\\\\d\+$/', $where)) {
            return '999999';
        }
        if (preg_match('/^\(?([a-z0-9_\/-]+)(\||\)|$)/i', $where, $m)) {
            return $m[1];
        }

        return '999999';
    }
}
