<?php

namespace App\Domain\Access;

use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

/**
 * Effective permissions of a membership (07 §6.2): resolved from the database on every request, cached per
 * (role id, role lock_version) so that editing a role takes effect immediately. The Owner role has every
 * permission (BR-060).
 */
final class PermissionResolver
{
    /** @return list<string> */
    public function forMembership(CompanyUser $membership): array
    {
        $role = Role::withoutGlobalScope('company')->find($membership->role_id);
        if (! $role) {
            return [];
        }
        if ($role->is_system) {
            return Cache::remember('perm:all', 300, fn () => Permission::orderBy('key')->pluck('key')->all());
        }

        return Cache::remember("perm:role:{$role->id}:{$role->lock_version}", 300,
            fn () => $role->permissions()->orderBy('key')->pluck('key')->all());
    }

    public function allows(CompanyUser $membership, string $permission): bool
    {
        return in_array($permission, $this->forMembership($membership), true);
    }
}
