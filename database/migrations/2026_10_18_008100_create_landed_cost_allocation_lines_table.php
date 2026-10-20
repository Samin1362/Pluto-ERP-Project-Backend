<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `landed_cost_allocation_lines` (LC): migration 81 of 83, dependency level 10.
 * Share of a landed-cost allocation per bill line; split between stock on hand and COGS.
 * Depends on: landed_cost_allocations, products, purchase_bill_lines, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_allocation_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Share of a landed-cost allocation per bill line; split between stock on hand and COGS.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('landed_cost_allocation_id');
            $table->unsignedBigInteger('purchase_bill_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('basis_value', 18, 2);
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('qty_received', 18, 3);
            $table->decimal('qty_on_hand', 18, 3);
            $table->decimal('amount_to_inventory', 18, 2);
            $table->decimal('amount_to_cogs', 18, 2);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['landed_cost_allocation_id'], 'landed_cost_allocation_lines_landed_cost_allocation_id_index');
            $table->index(['purchase_bill_line_id'], 'landed_cost_allocation_lines_purchase_bill_line_id_index');
            $table->index(['product_id'], 'landed_cost_allocation_lines_product_id_index');
            $table->index(['warehouse_id'], 'landed_cost_allocation_lines_warehouse_id_index');

            $table->foreign('landed_cost_allocation_id', 'landed_cost_allocation_lines_landed_cost_allocation_id_foreign')->references('id')->on('landed_cost_allocations')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_line_id', 'landed_cost_allocation_lines_purchase_bill_line_id_foreign')->references('id')->on('purchase_bill_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'landed_cost_allocation_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'landed_cost_allocation_lines_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `landed_cost_allocation_lines`
                    ADD CONSTRAINT `landed_cost_allocation_lines_rule1_check` CHECK (`amount_to_inventory` + `amount_to_cogs` = `allocated_amount`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocation_lines');
    }
};
