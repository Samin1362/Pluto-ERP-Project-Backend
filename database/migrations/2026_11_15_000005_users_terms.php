<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): Terms of service and Privacy policy acceptance (PEP-502) for databases created before
 * it. New databases get the columns from the generated users migration. Existing people keep NULL (accepted nothing yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'terms_version')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('terms_version', 20)->nullable()->after('last_login_at')
                    ->comment('Version of the Terms of service and Privacy policy this person accepted (PEP-502), e.g. 2026-11-15');
                $t->dateTime('terms_accepted_at')->nullable()->after('terms_version')->comment('When they accepted it (PEP-502)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'terms_version')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['terms_version', 'terms_accepted_at']));
        }
    }
};
