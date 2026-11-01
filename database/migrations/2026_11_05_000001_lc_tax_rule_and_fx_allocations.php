<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): P16 adds lc_charges.tax_rule_id (recoverable import tax, owner decision 1 Nov 2026) and
 * bill_allocations.foreign_amount / fx_amount (LC payments settling import bills, 06 §12.5). New databases get them from
 * the generated create migrations; this adds them to databases created before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lc_charges', 'tax_rule_id')) {
            Schema::table('lc_charges', function (Blueprint $t) {
                $t->unsignedBigInteger('tax_rule_id')->nullable()->after('landed_cost_allocation_id');
                $t->foreign('tax_rule_id', 'lc_charges_tax_rule_id_foreign')->references('id')->on('tax_rules')->restrictOnDelete()->restrictOnUpdate();
            });
        }
        if (! Schema::hasColumn('bill_allocations', 'fx_amount')) {
            Schema::table('bill_allocations', function (Blueprint $t) {
                $t->decimal('foreign_amount', 18, 2)->nullable()->after('allocated_on');
                $t->decimal('fx_amount', 18, 2)->default(0)->after('foreign_amount');
            });
        }
    }

    public function down(): void
    {
        // The generated create migrations own these columns on new databases; nothing to undo here.
    }
};
