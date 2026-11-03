<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): P10.7 line units (PEP-464, BR-069) for databases created before it. New databases get
 * product_units and the line columns from the generated create migrations; this adds the line columns to existing
 * ones. Existing lines keep unit_id NULL (= the product's base unit) and unit_factor 1, so nothing they posted changes.
 */
return new class extends Migration
{
    private const TABLES = ['quotation_lines', 'sales_invoice_lines', 'purchase_order_lines', 'purchase_bill_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'unit_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('unit_id')->nullable()->after('unit_name')
                    ->comment("Unit the quantity and price are in; NULL on rows saved before P10.7 = the product's base unit");
                $t->decimal('unit_factor', 18, 6)->default(1)->after('unit_id')
                    ->comment('Base units per one line unit, snapshot at save; stock moves quantity x unit_factor (BR-069)');
                $t->index(['unit_id'], "{$table}_unit_id_index");
                $t->foreign('unit_id', "{$table}_unit_id_foreign")->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'unit_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropForeign("{$table}_unit_id_foreign");
                    $t->dropIndex("{$table}_unit_id_index");
                    $t->dropColumn(['unit_id', 'unit_factor']);
                });
            }
        }
    }
};
