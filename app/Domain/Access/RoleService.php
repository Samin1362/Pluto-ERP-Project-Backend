<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles and permissions editor (K11; FR-007; BR-060; R-10; backlog P15.1-BE-06). The system Owner role is locked (it
 * always has every permission and no limits). Custom roles need both approval limits (R-10); a role with members
 * cannot be deleted. Editing a role bumps its version, which is part of the permission cache key, so the change applies
 * at the next request (07 §6.2).
 */
final class RoleService
{
    public function __construct(private readonly PermissionResolver $permissions, private readonly AuditLogger $audit) {}

    /** Role (05 schema). */
    public function resource(Role $r): array
    {
        $perms = $r->is_system ? Permission::orderBy('key')->pluck('key')->all() : $r->permissions()->orderBy('key')->pluck('key')->all();

        return ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description, 'is_system' => (bool) $r->is_system,
            'members_count' => CompanyUser::where('role_id', $r->id)->whereIn('status', ['active', 'invited'])->count(), 'permissions' => $perms,
            'payment_approval_limit' => $r->payment_approval_limit !== null ? (string) Money::of((string) $r->payment_approval_limit) : null,
            'journal_approval_limit' => $r->journal_approval_limit !== null ? (string) Money::of((string) $r->journal_approval_limit) : null,
            'version' => (int) $r->lock_version];
    }

    /** PermissionList: the catalogue grouped by module, actions in a fixed order (the K11 matrix columns). */
    public function catalogue(): array
    {
        $order = ['view' => 0, 'create' => 1, 'edit' => 2, 'approve' => 3, 'delete' => 4];

        return ['modules' => Permission::orderBy('module')->get()->groupBy('module')->map(fn ($g, $module) => ['module' => $module,
            'permissions' => $g->sortBy(fn ($p) => [$order[$p->action] ?? 9, $p->action])->values()->map(fn ($p) => ['key' => $p->key, 'action' => $p->action,
                'description' => $p->description, 'special' => ! isset($order[$p->action])])->all()])->values()->all()];
    }

    public function create(array $in): Role
    {
        return DB::transaction(function () use ($in) {
            $perms = $in['permissions'];
            if (! empty($in['copy_from_role_id'])) {
                $from = Role::findOrFail($in['copy_from_role_id']);
                $perms = array_values(array_unique([...$perms, ...$this->resource($from)['permissions']]));
            }
            $slug = Str::slug($in['name']);
            if (Role::where('slug', $slug)->exists() || in_array($slug, ['owner'], true)) {
                throw new ApiProblem('validation_failed', errors: ['name' => ['Another role already has this name']]);
            }
            $r = Role::create(['name' => $in['name'], 'slug' => $slug, 'description' => $in['description'] ?? null, 'is_system' => false,
                'payment_approval_limit' => $in['payment_approval_limit'], 'journal_approval_limit' => $in['journal_approval_limit']]);
            $r->permissions()->sync($this->ids($perms));
            $this->audit->record('created', $r, null, ['name' => $r->name, 'permissions' => $perms], type: 'roles');

            return $r->refresh();
        });
    }

    public function replace(int $id, array $in, int $version): Role
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $r = Role::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($r->is_system) {
                throw new ApiProblem('system_role', 'The Owner role always has every permission and cannot be edited.');
            }
            if ((int) $r->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $r->lock_version]);
            }
            $slug = Str::slug($in['name']);
            if ($slug !== $r->slug && (Role::where('slug', $slug)->exists() || $slug === 'owner')) {
                throw new ApiProblem('validation_failed', errors: ['name' => ['Another role already has this name']]);
            }
            $old = $this->resource($r);
            $r->fill(['name' => $in['name'], 'description' => $in['description'] ?? null, 'payment_approval_limit' => $in['payment_approval_limit'],
                'journal_approval_limit' => $in['journal_approval_limit']]);
            if (! $r->isDirty()) {
                $r->lock_version = (int) $r->lock_version + 1; // always a new version, so cached permissions refresh when only the matrix changed
            }
            $r->save();
            $r->permissions()->sync($this->ids($in['permissions']));
            $this->audit->record('updated', $r, ['permissions' => $old['permissions'], 'payment_approval_limit' => $old['payment_approval_limit'],
                'journal_approval_limit' => $old['journal_approval_limit']], ['permissions' => $in['permissions'],
                    'payment_approval_limit' => $in['payment_approval_limit'], 'journal_approval_limit' => $in['journal_approval_limit']], type: 'roles');

            return $r->refresh();
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $r = Role::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($r->is_system) {
                throw new ApiProblem('system_role', 'The Owner role cannot be deleted.');
            }
            if (CompanyUser::where('role_id', $r->id)->exists() || DB::table('user_invitations')->where('role_id', $r->id)->whereNull('accepted_at')->whereNull('revoked_at')->exists()) {
                throw new ApiProblem('role_in_use', "{$r->name} still has members or open invitations; move them to another role first.");
            }
            $r->permissions()->detach();
            DB::table('user_invitations')->where('role_id', $r->id)->delete();
            $r->delete();
            $this->audit->record('deleted', $r, ['name' => $r->name], null, type: 'roles');
        });
    }

    /** @param  list<string>  $keys  @return list<int> */
    private function ids(array $keys): array
    {
        $ids = Permission::whereIn('key', $keys)->pluck('id', 'key');
        $unknown = array_diff($keys, $ids->keys()->all());
        if ($unknown !== []) {
            throw new ApiProblem('validation_failed', errors: ['permissions' => ['Unknown permission: '.implode(', ', $unknown)]]);
        }

        return $ids->values()->all();
    }
}
