<?php

use App\Domain\Access\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): payroll for databases made before it (PEP-520). New companies get all of this when
 * they are provisioned. Adds the payroll.* permissions, gives them to each company's standard roles as the role
 * templates say (Owner all; Finance manager view/create/edit/approve; Accountant view/create), puts the payroll module
 * in the Business and Import Pro plans, and starts an EMP- number series per company. Custom roles get nothing.
 */
return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'edit', 'approve', 'delete'];

    public function up(): void
    {
        foreach (self::ACTIONS as $a) {
            DB::table('permissions')->updateOrInsert(['key' => "payroll.{$a}"],
                ['module' => 'payroll', 'action' => $a, 'description' => ucfirst($a).' payroll', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->where('module', 'payroll')->pluck('id', 'key');
        foreach (DB::table('roles')->whereIn('slug', array_keys(RoleTemplates::ROLES))->get(['id', 'slug']) as $role) {
            $keys = $role->slug === 'owner' ? $ids->keys()->all() : array_values(array_filter(RoleTemplates::ROLES[$role->slug][1], fn ($k) => str_starts_with($k, 'payroll.')));
            foreach ($keys as $k) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $ids[$k]]);
            }
        }
        foreach (DB::table('plans')->whereIn('slug', ['business', 'import_pro'])->get(['id', 'modules']) as $plan) {
            $modules = (array) json_decode((string) $plan->modules, true);
            if (! in_array('payroll', $modules, true)) {
                $modules[] = 'payroll';
                DB::table('plans')->where('id', $plan->id)->update(['modules' => json_encode(array_values($modules)), 'updated_at' => now()]);
            }
        }
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('number_sequences')->where('company_id', $companyId)->where('document_type', 'employee')->exists()) {
                DB::table('number_sequences')->insert(['company_id' => $companyId, 'fiscal_year_id' => null, 'document_type' => 'employee', 'prefix' => 'EMP-',
                    'next_number' => 1, 'padding' => 4, 'reset_policy' => 'never', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('module', 'payroll')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('number_sequences')->where('document_type', 'employee')->delete();
        foreach (DB::table('plans')->get(['id', 'modules']) as $plan) {
            $modules = array_values(array_diff((array) json_decode((string) $plan->modules, true), ['payroll']));
            DB::table('plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
        }
    }
};
