<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): Pluto plans (PEP-498). The generated create migration of `plans` runs after the released
 * `companies` one, so companies.plan_id is a declared soft reference (schema.py POLYMORPHIC; the API checks the plan
 * exists); this migration adds the columns to existing databases, seeds the three starting plans (the owner edits them in the platform console) and
 * puts every existing company on import_pro, so nothing a company uses today disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'plan_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->unsignedBigInteger('plan_id')->nullable()->after('brand_color')
                    ->comment('Pluto plan (PEP-498; plans.id). NULL = import_pro');
                $t->json('plan_overrides')->nullable()->after('plan_id')
                    ->comment('Per-company exceptions to the plan (PEP-498): {max_users?, max_branches?, modules_on?: [], modules_off?: []}');
            });
        }

        $now = now();
        foreach ([
            ['slug' => 'basic', 'name' => 'Basic', 'max_users' => 3, 'max_branches' => 1, 'modules' => []],
            ['slug' => 'business', 'name' => 'Business', 'max_users' => 10, 'max_branches' => 3, 'modules' => ['lc_management']],
            ['slug' => 'import_pro', 'name' => 'Import Pro', 'max_users' => 25, 'max_branches' => null, 'modules' => ['lc_management', 'lc_finance']],
        ] as $p) {
            if (! DB::table('plans')->where('slug', $p['slug'])->exists()) {
                DB::table('plans')->insert(['modules' => json_encode($p['modules'])] + $p + ['is_active' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Every company so far goes on import_pro (all modules). companies.seat_limit holds the effective user limit:
        // raised to the plan's 25; a company that already had more keeps it as its own exception.
        $pro = DB::table('plans')->where('slug', 'import_pro')->first();
        foreach (DB::table('companies')->whereNull('plan_id')->get(['id', 'seat_limit']) as $c) {
            $more = (int) $c->seat_limit > (int) $pro->max_users;
            DB::table('companies')->where('id', $c->id)->update(['plan_id' => $pro->id, 'seat_limit' => max((int) $c->seat_limit, (int) $pro->max_users),
                'plan_overrides' => $more ? json_encode(['max_users' => (int) $c->seat_limit]) : null]);
        }

    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'plan_id')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['plan_id', 'plan_overrides']));
        }
    }
};
