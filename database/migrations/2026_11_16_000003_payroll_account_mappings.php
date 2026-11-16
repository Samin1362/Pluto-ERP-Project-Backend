<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): the salary sheet's posting accounts (PEP-521) for companies made before payroll.
 * salaries_expense → ledger 6001 Salaries and wages, salaries_payable → ledger 2201 Salaries payable, when the company
 * still has those ledgers and the key is not mapped yet. New companies get them when they are provisioned.
 */
return new class extends Migration
{
    private const KEYS = ['salaries_expense' => '6001', 'salaries_payable' => '2201'];

    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::KEYS as $key => $code) {
                if (DB::table('account_mappings')->where('company_id', $companyId)->where('mapping_key', $key)->exists()) {
                    continue;
                }
                $ledgerId = DB::table('ledgers')->where('company_id', $companyId)->where('code', $code)->value('id');
                if ($ledgerId) {
                    DB::table('account_mappings')->insert(['company_id' => $companyId, 'mapping_key' => $key, 'ledger_id' => $ledgerId, 'account_group_id' => null,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('account_mappings')->whereIn('mapping_key', array_keys(self::KEYS))->delete();
    }
};
