<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `product_categories` (Inventory): migration 30 of 83, dependency level 3.
 * Browsing and pricing categories, two levels (D5).
 * Depends on: companies, stock_groups, units, users. Self-reference: parent_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Browsing and pricing categories, two levels (D5).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 80);
            $table->string('code_prefix', 10)->nullable();
            $table->unsignedBigInteger('default_unit_id')->nullable();
            $table->unsignedBigInteger('default_stock_group_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'name'], 'product_categories_company_id_name_unique');
            $table->index(['parent_id'], 'product_categories_parent_id_index');
            $table->index(['default_unit_id'], 'product_categories_default_unit_id_index');
            $table->index(['default_stock_group_id'], 'product_categories_default_stock_group_id_index');
            $table->index(['created_by'], 'product_categories_created_by_index');
            $table->index(['updated_by'], 'product_categories_updated_by_index');

            $table->foreign('company_id', 'product_categories_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('parent_id', 'product_categories_parent_id_foreign')->references('id')->on('product_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('default_unit_id', 'product_categories_default_unit_id_foreign')->references('id')->on('units')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('default_stock_group_id', 'product_categories_default_stock_group_id_foreign')->references('id')->on('stock_groups')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'product_categories_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'product_categories_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
