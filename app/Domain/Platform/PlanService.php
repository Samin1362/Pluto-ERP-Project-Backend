<?php

namespace App\Domain\Platform;

use App\Domain\Approvals\NotificationService;
use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Models\Plan;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Pluto plans in the platform console (PEP-498; owner decision 14 Nov 2026): the software owner edits plans and puts
 * each company on one, with its own exceptions. Every number takes effect at once; lowering a limit removes nobody
 * (they only cannot add more) and switching a module off deletes nothing.
 */
final class PlanService
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications,
        private readonly PlatformCompanyService $companies) {}

    /** @return list<array<string, mixed>> Plan[] */
    public function all(): array
    {
        $counts = DB::table('companies')->whereNotNull('plan_id')->groupBy('plan_id')->selectRaw('plan_id, COUNT(*) n')->pluck('n', 'plan_id');

        return Plan::orderBy('max_users')->orderBy('id')->get()->map(fn (Plan $p) => Plans::resource($p, (int) ($counts[$p->id] ?? 0)))->all();
    }

    /** @param array<string, mixed> $in PlanInput */
    public function create(array $in): array
    {
        $p = DB::transaction(function () use ($in) {
            $p = Plan::create(self::fields($in) + ['is_active' => $in['is_active'] ?? true]);
            // A plan is platform-wide: its audit entry has no company.
            $this->audit->record('created', $p, null, self::fields($in) + ['by' => 'platform'], type: 'plans');

            return $p;
        });

        return Plans::resource($p->refresh());
    }

    /** @param array<string, mixed> $in PlanInput */
    public function update(int $id, array $in, int $version): array
    {
        $p = DB::transaction(function () use ($id, $in, $version) {
            $p = Plan::query()->whereKey($id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            if ((int) $p->lock_version !== $version) {
                throw new ApiProblem('version_conflict');
            }
            $old = $p->only(['slug', 'name', 'max_users', 'max_branches', 'modules', 'is_active']);
            $p->update(self::fields($in) + (array_key_exists('is_active', $in) ? ['is_active' => (bool) $in['is_active']] : []));
            $this->audit->record('updated', $p, $old, $p->only(array_keys($old)) + ['by' => 'platform'], type: 'plans');
            // Every company on this plan follows at once.
            Plans::sync(DB::table('companies')->where('plan_id', $p->id)->pluck('id')->map(fn ($i) => (int) $i)->all());

            return $p;
        });

        return Plans::resource($p->refresh());
    }

    /** Puts a company on a plan with optional exceptions; audited in that company, its Owners are told. */
    public function assign(int $companyId, int $planId, ?array $overrides): array
    {
        DB::transaction(function () use ($companyId, $planId, $overrides) {
            $c = Company::query()->whereKey($companyId)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            $plan = Plan::find($planId) ?? throw new ApiProblem('validation_failed', errors: ['plan_id' => ['Choose a plan']]);
            if (! $plan->is_active && (int) $c->plan_id !== (int) $plan->id) {
                throw new ApiProblem('validation_failed', errors: ['plan_id' => ["{$plan->name} is no longer offered; choose another plan"]]);
            }
            $over = self::cleanOverrides($overrides ?? []);
            $old = ['plan' => Plans::planOf($c)?->slug, 'overrides' => Plans::overrides($c->plan_overrides) ?: null];
            $c->forceFill(['plan_id' => $plan->id, 'plan_overrides' => $over ?: null])->save();
            Plans::sync([$c->id]);
            $this->audit->record('plan_changed', $c, $old, ['plan' => $plan->slug, 'overrides' => $over ?: null, 'by' => 'platform'], companyId: $c->id, type: 'companies');
            $owners = DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $c->id)->where('r.slug', 'owner')
                ->where('m.status', 'active')->where('m.joined_via', 'member')->pluck('m.user_id')->all();
            $e = Plans::effective($c->id);
            $mods = $e['modules'] ? implode(', ', array_map(fn ($m) => Plans::LABELS[$m] ?? $m, $e['modules'])) : 'no add-on modules';
            $this->notifications->notify($c->id, $owners, 'plan_changed', "Your Pluto plan is now {$plan->name}",
                "Up to {$e['max_users']} users, ".($e['max_branches'] === null ? 'unlimited branches' : "up to {$e['max_branches']} branches").", {$mods}.");
        });

        return $this->companies->one($companyId);
    }

    /** @return array<string, mixed> */
    private static function fields(array $in): array
    {
        return ['slug' => $in['slug'], 'name' => $in['name'], 'max_users' => (int) $in['max_users'],
            'max_branches' => isset($in['max_branches']) ? (int) $in['max_branches'] : null, 'modules' => array_values(array_unique($in['modules'] ?? []))];
    }

    /** Keeps only the exceptions actually given; an empty result means "follow the plan". */
    private static function cleanOverrides(array $o): array
    {
        $out = [];
        foreach (['max_users', 'max_branches'] as $k) {
            if (isset($o[$k])) {
                $out[$k] = (int) $o[$k];
            }
        }
        foreach (['modules_on', 'modules_off'] as $k) {
            if (! empty($o[$k])) {
                $out[$k] = array_values(array_unique($o[$k]));
            }
        }

        return $out;
    }
}
