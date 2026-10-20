<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `product_warehouse_settings` (Inventory): migration 56 of 83, dependency level 6.
 * Per-warehouse reorder level and reorder quantity.
 * Depends on: companies, products, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warehouse_settings', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Per-warehouse reorder level and reorder quantity.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('reorder_level', 18, 3)->default(0);
            $table->decimal('reorder_qty', 18, 3)->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['product_id', 'warehouse_id'], 'product_warehouse_settings_product_id_warehouse_id_unique');
            $table->index(['company_id'], 'product_warehouse_settings_company_id_index');
            $table->index(['warehouse_id'], 'product_warehouse_settings_warehouse_id_index');
            $table->index(['created_by'], 'product_warehouse_settings_created_by_index');
            $table->index(['updated_by'], 'product_warehouse_settings_updated_by_index');

            $table->foreign('company_id', 'product_warehouse_settings_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'product_warehouse_settings_product_id_foreign')->references('id')->on('products')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'product_warehouse_settings_warehouse_id_foreign')->references('id')->on('warehouses')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'product_warehouse_settings_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'product_warehouse_settings_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `product_warehouse_settings`
                    ADD CONSTRAINT `product_warehouse_settings_rule1_check` CHECK (`reorder_level` >= 0 AND `reorder_qty` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouse_settings');
    }
};
