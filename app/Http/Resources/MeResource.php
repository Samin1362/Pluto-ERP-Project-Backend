<?php

namespace App\Http\Resources;

use App\Domain\Access\PermissionResolver;
use App\Models\Company;
use App\Models\Role;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Me schema: the user, memberships, and - when X-Company-Id is sent - effective permissions in that company. */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $u = $this->resource;
        $current = app(CurrentCompany::class);
        $memberships = $u->memberships()->whereIn('status', ['active', 'invited'])->get();
        $companies = Company::whereIn('id', $memberships->pluck('company_id'))->pluck('legal_name', 'id');
        $roles = Role::withoutGlobalScope('company')->whereIn('id', $memberships->pluck('role_id'))->pluck('name', 'id');
        $out = [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'two_factor_enabled' => $u->two_factor_confirmed_at !== null,
            'companies' => $memberships->map(fn ($m) => ['company_id' => $m->company_id, 'company_name' => $companies[$m->company_id] ?? '',
                'role' => $roles[$m->role_id] ?? '', 'status' => $m->status])->values()->all(),
            'active_company_id' => $current->has() ? $current->id() : null,
        ];
        if ($current->has() && ($m = $current->membership())) {
            $role = Role::withoutGlobalScope('company')->find($m->role_id);
            $out['permissions'] = app(PermissionResolver::class)->forMembership($m);
            $out['approval_limits'] = ['payment' => $role?->payment_approval_limit, 'journal' => $role?->journal_approval_limit];
            $out['branch_ids'] = $m->branches()->pluck('branches.id')->all();
        }

        return $out;
    }
}
