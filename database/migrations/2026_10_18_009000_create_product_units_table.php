<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `product_units` (Inventory): migration 90 of 92, dependency level 6.
 * Extra units an item is bought or sold in, each worth `factor` of its base unit (products.unit_id), e.g. 1 box = 12 pcs (BR-069). Stock is always kept in the base unit.
 * Depends on: companies, products, units, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Extra units an item is bought or sold in, each worth `factor` of its base unit (products.unit_id), e.g. 1 box = 12 pcs (BR-069). Stock is always kept in the base unit.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('factor', 18, 6)->comment('Base units in one of this unit');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['product_id', 'unit_id'], 'product_units_product_id_unit_id_unique');
            $table->index(['company_id'], 'product_units_company_id_index');
            $table->index(['unit_id'], 'product_units_unit_id_index');
            $table->index(['created_by'], 'product_units_created_by_index');
            $table->index(['updated_by'], 'product_units_updated_by_index');

            $table->foreign('company_id', 'product_units_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'product_units_product_id_foreign')->references('id')->on('products')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('unit_id', 'product_units_unit_id_foreign')->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'product_units_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'product_units_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `product_units`
                    ADD CONSTRAINT `product_units_rule1_check` CHECK (`factor` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
