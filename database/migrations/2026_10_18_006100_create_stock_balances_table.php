<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_balances` (Inventory): migration 61 of 83, dependency level 6.
 * Quantity, value and moving weighted average cost per product and warehouse (R-02). Maintained only by stock posting, locked FOR UPDATE. Company valuation = SUM over warehouses.
 * Depends on: companies, products, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Quantity, value and moving weighted average cost per product and warehouse (R-02). Maintained only by stock posting, locked FOR UPDATE. Company valuation = SUM over warehouses.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('qty_on_hand', 18, 3)->default(0);
            $table->decimal('qty_in_transit_out', 18, 3)->default(0);
            $table->decimal('total_value', 18, 2)->default(0)->comment('Inventory value of qty_on_hand; equals the warehouse ledger\'s share');
            $table->decimal('avg_unit_cost', 18, 4)->default(0)->comment('total_value / qty_on_hand, recomputed on every stock-in (BR-017)');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['product_id', 'warehouse_id'], 'stock_balances_product_id_warehouse_id_unique');
            $table->index(['company_id', 'warehouse_id'], 'stock_balances_company_id_warehouse_id_index');
            $table->index(['warehouse_id'], 'stock_balances_warehouse_id_index');

            $table->foreign('company_id', 'stock_balances_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'stock_balances_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'stock_balances_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `stock_balances`
                    ADD CONSTRAINT `stock_balances_rule1_check` CHECK (`qty_in_transit_out` >= 0),
                    ADD CONSTRAINT `stock_balances_rule2_check` CHECK (`avg_unit_cost` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
