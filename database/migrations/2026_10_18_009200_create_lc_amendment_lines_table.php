<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_amendment_lines` (LC): migration 92 of 92, dependency level 8.
 * Item change of an amendment (PEP-487): add, change or remove an item of the LC. An accepted amendment applies them to the linked purchase order's lines.
 * Depends on: lc_amendments, products, purchase_order_lines, units.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_amendment_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Item change of an amendment (PEP-487): add, change or remove an item of the LC. An accepted amendment applies them to the linked purchase order\'s lines.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('lc_amendment_id');
            $table->string('action', 30)->comment('One of: add, change, remove');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable()->comment('The LC item changed or removed; the new line once an added item is applied');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('Unit of an added item; NULL = the product\'s base unit');
            $table->string('description', 255)->nullable();
            $table->decimal('old_quantity', 18, 3)->nullable()->comment('Before the amendment');
            $table->decimal('quantity', 18, 3)->nullable()->comment('New quantity; NULL = unchanged or removed');
            $table->decimal('old_unit_cost', 18, 4)->nullable()->comment('Before the amendment, LC currency');
            $table->decimal('unit_cost', 18, 4)->nullable()->comment('New unit cost in the LC currency; NULL = unchanged or removed');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['lc_amendment_id'], 'lc_amendment_lines_lc_amendment_id_index');
            $table->index(['purchase_order_line_id'], 'lc_amendment_lines_purchase_order_line_id_index');
            $table->index(['product_id'], 'lc_amendment_lines_product_id_index');
            $table->index(['unit_id'], 'lc_amendment_lines_unit_id_index');

            $table->foreign('lc_amendment_id', 'lc_amendment_lines_lc_amendment_id_foreign')->references('id')->on('lc_amendments')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_order_line_id', 'lc_amendment_lines_purchase_order_line_id_foreign')->references('id')->on('purchase_order_lines')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'lc_amendment_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('unit_id', 'lc_amendment_lines_unit_id_foreign')->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_amendment_lines`
                    ADD CONSTRAINT `lc_amendment_lines_action_check` CHECK (`action` IN ('add', 'change', 'remove'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_amendment_lines');
    }
};
