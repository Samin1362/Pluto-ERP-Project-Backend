<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): P16 LC finance (PEP-451) for databases created before it. New companies get the ledgers
 * and mappings from CompanyProvisioner, and new databases get lc_payments.financed_by from the generated create
 * migration; this adds them to existing ones:
 * - ledgers 2303 "PAD/LTR loans (LC)" and 2304 "Interest payable" under current liabilities (21), system ledgers;
 * - mappings lc_loan -> 2303, interest_payable -> 2304, interest_expense -> 6601 "Interest on loans";
 * - lc_payments.financed_by (own | pad | ltr, default own).
 */
return new class extends Migration
{
    private const LEDGERS = ['2303' => 'PAD/LTR loans (LC)', '2304' => 'Interest payable'];

    private const MAPPINGS = ['lc_loan' => '2303', 'interest_payable' => '2304', 'interest_expense' => '6601'];

    public function up(): void
    {
        if (Schema::hasTable('lc_payments') && ! Schema::hasColumn('lc_payments', 'financed_by')) {
            Schema::table('lc_payments', function (Blueprint $t) {
                $t->enum('financed_by', ['own', 'pad', 'ltr'])->default('own')->after('journal_entry_id');
            });
        }
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $group = DB::table('account_groups')->where('company_id', $companyId)->where('code', '21')->value('id');
            if (! $group) {
                continue; // a company without the default chart keeps its own; map the keys in Accounting defaults
            }
            foreach (self::LEDGERS as $code => $name) {
                if (! DB::table('ledgers')->where('company_id', $companyId)->where('code', $code)->exists()) {
                    DB::table('ledgers')->insert(['company_id' => $companyId, 'account_group_id' => $group, 'code' => $code, 'name' => $name,
                        'ledger_kind' => 'general', 'show_in_cash_vouchers' => false, 'is_system' => true, 'is_active' => true,
                        'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            foreach (self::MAPPINGS as $key => $code) {
                $ledger = DB::table('ledgers')->where('company_id', $companyId)->where('code', $code)->value('id');
                if ($ledger && ! DB::table('account_mappings')->where('company_id', $companyId)->where('mapping_key', $key)->exists()) {
                    DB::table('account_mappings')->insert(['company_id' => $companyId, 'mapping_key' => $key, 'ledger_id' => $ledger,
                        'account_group_id' => null, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('account_mappings')->whereIn('mapping_key', array_keys(self::MAPPINGS))->delete();
        $ids = DB::table('ledgers')->whereIn('code', array_keys(self::LEDGERS))->pluck('id');
        $used = DB::table('journal_lines')->whereIn('ledger_id', $ids)->pluck('ledger_id')->unique();
        DB::table('ledgers')->whereIn('id', $ids->diff($used))->delete();
        if (Schema::hasColumn('lc_payments', 'financed_by')) {
            Schema::table('lc_payments', fn (Blueprint $t) => $t->dropColumn('financed_by'));
        }
    }
};
