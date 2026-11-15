<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): the setup checklist (PEP-511) for databases created before it. New databases get the
 * column from the generated company_settings migration. Existing companies keep NULL (the checklist shows until done).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_settings', 'setup_hidden_at')) {
            Schema::table('company_settings', function (Blueprint $t) {
                $t->dateTime('setup_hidden_at')->nullable()->after('backup_retention_days')
                    ->comment('When an Owner hid the setup checklist on the dashboard (PEP-511); NULL = shown until every step is done');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'setup_hidden_at')) {
            Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn('setup_hidden_at'));
        }
    }
};
