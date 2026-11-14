<?php

namespace App\Http\Resources;

use App\Domain\Access\PermissionResolver;
use App\Domain\Company\BrandPalette;
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
            // Platform console (K17, PEP-484): shows the Platform link; the /platform API checks it again with the 2-step sign-in.
            'platform_admin' => in_array(strtolower((string) $u->email), (array) config('pluto.platform_admins', []), true),
            'companies' => $memberships->map(fn ($m) => ['company_id' => $m->company_id, 'company_name' => $companies[$m->company_id] ?? '',
                'role' => $roles[$m->role_id] ?? '', 'status' => $m->status])->values()->all(),
            'active_company_id' => $current->has() ? $current->id() : null,
            // PEP-485: set while the current company is a platform admin's read-only support view (the app shows a banner).
            'support_access' => $request->attributes->get('support_access'),
        ];
        if ($current->has() && ($m = $current->membership())) {
            $role = Role::withoutGlobalScope('company')->find($m->role_id);
            $out['permissions'] = app(PermissionResolver::class)->forMembership($m);
            $out['approval_limits'] = ['payment' => $role?->payment_approval_limit, 'journal' => $role?->journal_approval_limit];
            $out['branch_ids'] = $m->branches()->pluck('branches.id')->all();
            // PEP-497: the current company's brand colours (null = the Pluto default), so the app is coloured from its first paint.
            $out['brand'] = BrandPalette::for(Company::whereKey($current->id())->value('brand_color'));
        }

        return $out;
    }
}
