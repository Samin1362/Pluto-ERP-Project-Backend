<?php

namespace App\Domain\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard freshness (OBJ-2; 05 GET /dashboard; backlog P14.1-BE-01): dashboards are cached per company for 60 s, and
 * every saved journal entry bumps the company's books version, which is part of the cache key, so a posting shows at once.
 */
final class DashboardCache
{
    public const TTL = 60;

    public static function version(int $companyId): int
    {
        return (int) Cache::get(self::key($companyId), 0);
    }

    public static function bump(int $companyId): void
    {
        $bump = function () use ($companyId) {
            if (! Cache::add(self::key($companyId), 1, now()->addDay())) {
                Cache::increment(self::key($companyId));
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($bump) : $bump();
    }

    /** @param  callable(): array  $build */
    public static function remember(int $companyId, string $name, callable $build): array
    {
        return Cache::remember("dashboard:{$companyId}:".self::version($companyId).":{$name}", self::TTL, $build);
    }

    private static function key(int $companyId): string
    {
        return "dashboard:{$companyId}:version";
    }
}
