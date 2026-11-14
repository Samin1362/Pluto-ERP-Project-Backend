<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): company brand colour (PEP-497) for databases created before it. New databases get
 * companies.brand_color from the generated create migration; this adds it to existing ones. Every existing company
 * keeps NULL (the Pluto default colour).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'brand_color')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->char('brand_color', 7)->nullable()->after('seat_limit')
                    ->comment('Company brand colour as #rrggbb (PEP-497); NULL = the Pluto default. The app and the PDFs of this company follow it');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'brand_color')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('brand_color'));
        }
    }
};
