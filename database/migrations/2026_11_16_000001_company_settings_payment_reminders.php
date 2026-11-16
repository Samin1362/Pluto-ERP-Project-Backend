<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): payment reminder settings (PEP-516) for databases created before them. New databases
 * get the columns from the generated company_settings migration. Existing companies start with reminders off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_settings', 'reminders_auto')) {
            Schema::table('company_settings', function (Blueprint $t) {
                $t->boolean('reminders_auto')->default(false)->after('setup_hidden_at')
                    ->comment('PEP-516: e-mail customers with overdue invoices automatically (daily job)');
                $t->unsignedSmallInteger('reminder_days_after_due')->default(7)->after('reminders_auto')
                    ->comment('PEP-516: days past the due date before the first automatic reminder');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'reminders_auto')) {
            Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn(['reminders_auto', 'reminder_days_after_due']));
        }
    }
};
