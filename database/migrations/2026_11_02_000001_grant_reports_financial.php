<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): DEC-13 (owner decision 25 Oct 2026; backlog P9.0-SETUP-01) adds `reports.financial`
 * for the trial balance, P&L, balance sheet, cash flow, general ledger and aging reports. New companies get it from
 * RoleTemplates; this grants it to the Owner, Finance manager and Accountant roles of companies created before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['key' => 'reports.financial'], [
            'module' => 'reports', 'action' => 'financial', 'description' => 'Financial reports', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', 'reports.financial')->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['owner', 'finance', 'accountant'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'reports.financial')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
