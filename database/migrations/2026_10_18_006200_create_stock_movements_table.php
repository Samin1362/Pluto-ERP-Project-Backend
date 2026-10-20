<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_movements` (Inventory): migration 62 of 83, dependency level 6.
 * Immutable stock ledger: one row per quantity or value change, linked to its source document and journal.
 * Depends on: companies, journal_entries, products, users, warehouses. Self-reference: reverses_movement_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Immutable stock ledger: one row per quantity or value change, linked to its source document and journal.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('movement_type', 30)->comment('One of: opening, purchase_in, sale_out, sales_return_in, purchase_return_out, adjustment_in, adjustment_out, transfer_out, transfer_in, landed_cost, reversal');
            $table->date('moved_on');
            $table->decimal('quantity_in', 18, 3)->default(0);
            $table->decimal('quantity_out', 18, 3)->default(0);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('value_in', 18, 2)->default(0);
            $table->decimal('value_out', 18, 2)->default(0);
            $table->decimal('qty_balance_after', 18, 3)->comment('Warehouse quantity after this movement');
            $table->decimal('avg_cost_after', 18, 4)->comment('Warehouse average cost after this movement');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reverses_movement_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'product_id', 'warehouse_id', 'moved_on'], 'stock_movements_company_id_product_id_warehouse_i_5584895c_index');
            $table->index(['company_id', 'source_type', 'source_id'], 'stock_movements_company_id_source_type_source_id_index');
            $table->index(['product_id'], 'stock_movements_product_id_index');
            $table->index(['warehouse_id'], 'stock_movements_warehouse_id_index');
            $table->index(['journal_entry_id'], 'stock_movements_journal_entry_id_index');
            $table->index(['reverses_movement_id'], 'stock_movements_reverses_movement_id_index');
            $table->index(['created_by'], 'stock_movements_created_by_index');
            $table->index(['updated_by'], 'stock_movements_updated_by_index');

            $table->foreign('company_id', 'stock_movements_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'stock_movements_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'stock_movements_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'stock_movements_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reverses_movement_id', 'stock_movements_reverses_movement_id_foreign')->references('id')->on('stock_movements')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'stock_movements_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'stock_movements_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `stock_movements`
                    ADD CONSTRAINT `stock_movements_movement_type_check` CHECK (`movement_type` IN ('opening', 'purchase_in', 'sale_out', 'sales_return_in', 'purchase_return_out', 'adjustment_in', 'adjustment_out', 'transfer_out', 'transfer_in', 'landed_cost', 'reversal')),
                    ADD CONSTRAINT `stock_movements_rule1_check` CHECK (`quantity_in` >= 0 AND `quantity_out` >= 0),
                    ADD CONSTRAINT `stock_movements_rule2_check` CHECK (NOT (`quantity_in` > 0 AND `quantity_out` > 0))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
