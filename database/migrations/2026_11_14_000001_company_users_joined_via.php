<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): owner access for the software owner (PEP-496) for databases created before it. New
 * databases get company_users.joined_via from the generated create migration; this adds it to existing ones. Every
 * existing membership keeps `member` (the customer's own people, each one a seat).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_users') && ! Schema::hasColumn('company_users', 'joined_via')) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->string('joined_via', 30)->default('member')->after('deactivated_by')
                    ->comment('One of: member, platform_admin. platform_admin: Pluto support joined as Owner from the platform console (PEP-496); shown as such to the company and never counted as a seat');
            });
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `company_users` ADD CONSTRAINT `company_users_joined_via_check` CHECK (`joined_via` IN ('member', 'platform_admin'))");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_users', 'joined_via')) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE `company_users` DROP CHECK `company_users_joined_via_check`');
            }
            Schema::table('company_users', fn (Blueprint $t) => $t->dropColumn('joined_via'));
        }
    }
};
