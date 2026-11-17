<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): the Festival bonus ledger and its mapping (PEP-524) for companies made before it.
 * Code 6002 under Operating expenses (group 61), or the next free code if a company already used 6002. New companies get
 * it when they are provisioned. down() removes only ledgers nothing was posted to.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (DB::table('account_mappings')->where('company_id', $companyId)->where('mapping_key', 'festival_bonus_expense')->exists()) {
                continue;
            }
            $groupId = DB::table('account_groups')->where('company_id', $companyId)->where('code', '61')->value('id');
            if (! $groupId) {
                continue; // a company without the standard chart: mapped by hand in Accounting defaults
            }
            $code = 6002;
            while (DB::table('ledgers')->where('company_id', $companyId)->where('code', (string) $code)->exists()) {
                $code++;
            }
            $ledgerId = DB::table('ledgers')->insertGetId(['company_id' => $companyId, 'account_group_id' => $groupId, 'code' => (string) $code, 'name' => 'Festival bonus',
                'ledger_kind' => 'general', 'show_in_cash_vouchers' => false, 'track_bills' => false, 'is_system' => true, 'is_active' => true, 'lock_version' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('account_mappings')->insert(['company_id' => $companyId, 'mapping_key' => 'festival_bonus_expense', 'ledger_id' => $ledgerId, 'account_group_id' => null,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('account_mappings')->where('mapping_key', 'festival_bonus_expense')->pluck('ledger_id');
        DB::table('account_mappings')->where('mapping_key', 'festival_bonus_expense')->delete();
        foreach ($ids as $id) {
            if (! DB::table('journal_lines')->where('ledger_id', $id)->exists()) {
                DB::table('ledgers')->where('id', $id)->delete();
            }
        }
    }
};
