<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `products` (Inventory): migration 48 of 83, dependency level 5.
 * A physical product (stock-tracked) or a service (never stocked).
 * Depends on: companies, ledgers, parties, product_categories, stock_groups, tax_rules, units, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A physical product (stock-tracked) or a service (never stocked).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('item_type', 30)->comment('One of: product, service');
            $table->string('code', 30);
            $table->string('name', 190);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('stock_group_id')->nullable();
            $table->unsignedBigInteger('unit_id');
            $table->string('barcode', 40)->nullable();
            $table->decimal('last_purchase_cost', 18, 4)->nullable()->comment('Informational; valuation uses stock_balances');
            $table->decimal('selling_price', 18, 2);
            $table->decimal('wholesale_price', 18, 2)->nullable();
            $table->unsignedBigInteger('tax_rule_id')->nullable();
            $table->unsignedBigInteger('income_ledger_id')->comment('Sales income ledger for this item');
            $table->unsignedBigInteger('expense_ledger_id')->nullable()->comment('Services/non-stock purchases');
            $table->boolean('track_stock')->default(true)->comment('Always 0 for services');
            $table->unsignedBigInteger('preferred_supplier_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('deleted_at')->nullable()->comment('Soft delete (master data only, never financial rows)');

            $table->unique(['company_id', 'code'], 'products_company_id_code_unique');
            $table->unique(['company_id', 'barcode'], 'products_company_id_barcode_unique');
            $table->index(['company_id', 'item_type', 'is_active'], 'products_company_id_item_type_is_active_index');
            $table->index(['company_id', 'name'], 'products_company_id_name_index');
            $table->index(['category_id'], 'products_category_id_index');
            $table->index(['stock_group_id'], 'products_stock_group_id_index');
            $table->index(['unit_id'], 'products_unit_id_index');
            $table->index(['tax_rule_id'], 'products_tax_rule_id_index');
            $table->index(['income_ledger_id'], 'products_income_ledger_id_index');
            $table->index(['expense_ledger_id'], 'products_expense_ledger_id_index');
            $table->index(['preferred_supplier_id'], 'products_preferred_supplier_id_index');
            $table->index(['created_by'], 'products_created_by_index');
            $table->index(['updated_by'], 'products_updated_by_index');

            $table->foreign('company_id', 'products_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('category_id', 'products_category_id_foreign')->references('id')->on('product_categories')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('stock_group_id', 'products_stock_group_id_foreign')->references('id')->on('stock_groups')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('unit_id', 'products_unit_id_foreign')->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('tax_rule_id', 'products_tax_rule_id_foreign')->references('id')->on('tax_rules')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('income_ledger_id', 'products_income_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('expense_ledger_id', 'products_expense_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('preferred_supplier_id', 'products_preferred_supplier_id_foreign')->references('id')->on('parties')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'products_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'products_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `products`
                    ADD CONSTRAINT `products_item_type_check` CHECK (`item_type` IN ('product', 'service')),
                    ADD CONSTRAINT `products_rule1_check` CHECK (`selling_price` >= 0),
                    ADD CONSTRAINT `products_rule2_check` CHECK (`item_type` = 'product' OR `track_stock` = 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
