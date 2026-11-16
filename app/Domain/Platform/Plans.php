<?php

namespace App\Domain\Platform;

use App\Models\Company;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Pluto plans (PEP-498): what a company may use. The effective limits are its plan's, changed by the company's own
 * exceptions (companies.plan_overrides). A company with no plan is on import_pro (everything), so nothing it used
 * before plans existed disappears. Lowering a limit removes nobody; switching a module off deletes nothing.
 */
final class Plans
{
    /** Module keys a plan can include. lc_finance needs lc_management. */
    public const LC_MANAGEMENT = 'lc_management';

    public const LC_FINANCE = 'lc_finance';

    /** PEP-520: employees, salary sheets and payments. */
    public const PAYROLL = 'payroll';

    public const MODULES = [self::LC_MANAGEMENT, self::LC_FINANCE, self::PAYROLL];

    public const LABELS = [self::LC_MANAGEMENT => 'LC management', self::LC_FINANCE => 'LC finance', self::PAYROLL => 'Payroll'];

    public const DEFAULT_SLUG = 'import_pro';

    /** The starting plans (owner decision 14 Nov 2026); the platform console changes them later. */
    public const DEFAULTS = [
        ['slug' => 'basic', 'name' => 'Basic', 'max_users' => 3, 'max_branches' => 1, 'modules' => []],
        ['slug' => 'business', 'name' => 'Business', 'max_users' => 10, 'max_branches' => 3, 'modules' => [self::LC_MANAGEMENT, self::PAYROLL]],
        ['slug' => 'import_pro', 'name' => 'Import Pro', 'max_users' => 25, 'max_branches' => null, 'modules' => [self::LC_MANAGEMENT, self::LC_FINANCE, self::PAYROLL]],
    ];

    /** Inserts the starting plans that are missing (by slug); never changes a plan the owner has edited. */
    public static function seedDefaults(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('plans')) {
            return;
        }
        foreach (self::DEFAULTS as $p) {
            if (! DB::table('plans')->where('slug', $p['slug'])->exists()) {
                DB::table('plans')->insert(['modules' => json_encode($p['modules'])] + $p + ['is_active' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public static function planOf(Company|int $company): ?Plan
    {
        $planId = $company instanceof Company ? $company->plan_id : DB::table('companies')->where('id', $company)->value('plan_id');

        return ($planId ? Plan::find($planId) : null) ?? Plan::where('slug', self::DEFAULT_SLUG)->first();
    }

    /** @return array{max_users: int, max_branches: ?int, modules: list<string>} */
    public static function effective(Company|int $company): array
    {
        $row = $company instanceof Company ? $company : Company::find($company);
        $plan = $row ? self::planOf($row) : null;
        $over = $row ? self::overrides($row->plan_overrides) : [];
        // Without any plan row (a database before PEP-498 was migrated) everything stays available, as before.
        $modules = $plan ? (array) $plan->modules : self::MODULES;
        $modules = array_values(array_diff(array_unique(array_merge($modules, $over['modules_on'] ?? [])), $over['modules_off'] ?? []));
        if (! in_array(self::LC_MANAGEMENT, $modules, true)) {
            $modules = array_values(array_diff($modules, [self::LC_FINANCE]));
        }
        sort($modules);

        return [
            // companies.seat_limit holds the effective user limit (kept in step by sync()), so the existing seat checks
            // (BR-059) and Settings › Users ("7 of 10") keep reading one number.
            'max_users' => (int) ($row->seat_limit ?? 10),
            'max_branches' => array_key_exists('max_branches', $over) ? $over['max_branches'] : ($plan ? $plan->max_branches : null),
            'modules' => $modules,
        ];
    }

    /** The user limit a plan and a company's exceptions give: what sync() stores in companies.seat_limit. */
    public static function maxUsers(?Plan $plan, array $over, int $fallback): int
    {
        return (int) ($over['max_users'] ?? $plan?->max_users ?? $fallback);
    }

    /** Stores the effective user limit of these companies in companies.seat_limit (after a plan or exception change). */
    public static function sync(array $companyIds): void
    {
        foreach (Company::whereIn('id', $companyIds)->get() as $c) {
            $limit = self::maxUsers(self::planOf($c), self::overrides($c->plan_overrides), (int) $c->seat_limit);
            if ((int) $c->seat_limit !== $limit) {
                DB::table('companies')->where('id', $c->id)->update(['seat_limit' => $limit]);
            }
        }
    }

    public static function has(Company|int $company, string $module): bool
    {
        return in_array($module, self::effective($company)['modules'], true);
    }

    /** @return array{max_users?: ?int, max_branches?: ?int, modules_on?: list<string>, modules_off?: list<string>} */
    public static function overrides(mixed $raw): array
    {
        $o = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($o) ? $o : [];
    }

    /** Active branches of a company, the number its branch limit counts. */
    public static function branchesUsed(int $companyId): int
    {
        return DB::table('branches')->where('company_id', $companyId)->where('is_active', true)->count();
    }

    /** @return array<string, mixed> Plan */
    public static function resource(Plan $p, ?int $companies = null): array
    {
        return ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'max_users' => (int) $p->max_users, 'max_branches' => $p->max_branches === null ? null : (int) $p->max_branches,
            'modules' => array_values((array) $p->modules), 'is_active' => (bool) $p->is_active,
            'companies' => $companies ?? DB::table('companies')->where('plan_id', $p->id)->count(), 'version' => (int) $p->lock_version];
    }
}
