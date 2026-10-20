<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `sales_invoice_lines` (Sales): migration 67 of 83, dependency level 7.
 * Invoice line with price, discount, tax and the cost snapshot used for COGS.
 * Depends on: ledgers, products, sales_invoices, tax_rules.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Invoice line with price, discount, tax and the cost snapshot used for COGS.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('sales_invoice_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 30)->comment('Snapshot at save (R-30)');
            $table->string('product_name', 190)->comment('Snapshot at save');
            $table->string('unit_name', 30)->comment('Snapshot at save');
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_pct', 7, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->unsignedBigInteger('tax_rule_id')->nullable()->comment('RESTRICT: posted lines are immutable');
            $table->decimal('tax_rate', 7, 4)->default(0)->comment('Snapshot at save');
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->comment('qty x price - discount');
            $table->decimal('line_total', 18, 2)->comment('net + tax');
            $table->unsignedBigInteger('income_ledger_id')->comment('Snapshot of product income ledger');
            $table->decimal('unit_cost', 18, 4)->nullable()->comment('Average cost at posting (stocked items)');
            $table->decimal('cost_amount', 18, 2)->nullable();
            $table->decimal('returned_quantity', 18, 3)->default(0);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['sales_invoice_id', 'line_no'], 'sales_invoice_lines_sales_invoice_id_line_no_unique');
            $table->index(['product_id'], 'sales_invoice_lines_product_id_index');
            $table->index(['tax_rule_id'], 'sales_invoice_lines_tax_rule_id_index');
            $table->index(['income_ledger_id'], 'sales_invoice_lines_income_ledger_id_index');

            $table->foreign('sales_invoice_id', 'sales_invoice_lines_sales_invoice_id_foreign')->references('id')->on('sales_invoices')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'sales_invoice_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('tax_rule_id', 'sales_invoice_lines_tax_rule_id_foreign')->references('id')->on('tax_rules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('income_ledger_id', 'sales_invoice_lines_income_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `sales_invoice_lines`
                    ADD CONSTRAINT `sales_invoice_lines_rule1_check` CHECK (`quantity` > 0),
                    ADD CONSTRAINT `sales_invoice_lines_rule2_check` CHECK (`unit_price` >= 0),
                    ADD CONSTRAINT `sales_invoice_lines_rule3_check` CHECK (`returned_quantity` <= `quantity`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
