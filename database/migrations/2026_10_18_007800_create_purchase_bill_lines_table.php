<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `purchase_bill_lines` (Purchases): migration 78 of 83, dependency level 9.
 * Bill line: stocked product (debits inventory) or non-stock/service (debits expense ledger).
 * Depends on: ledgers, products, purchase_bills, purchase_order_lines, tax_rules.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bill_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Bill line: stocked product (debits inventory) or non-stock/service (debits expense ledger).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('purchase_bill_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 30)->comment('Snapshot at save (R-30)');
            $table->string('product_name', 190)->comment('Snapshot at save');
            $table->string('unit_name', 30)->comment('Snapshot at save');
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4)->comment('In bill currency');
            $table->decimal('discount_pct', 7, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->unsignedBigInteger('tax_rule_id')->nullable()->comment('RESTRICT: posted lines are immutable');
            $table->decimal('tax_rate', 7, 4)->default(0)->comment('Snapshot at save');
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->comment('qty x price - discount');
            $table->decimal('line_total', 18, 2)->comment('net + tax');
            $table->decimal('base_net_amount', 18, 2)->comment('net_amount x exchange_rate, BDT');
            $table->unsignedBigInteger('debit_ledger_id')->comment('Inventory or expense ledger snapshot');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->decimal('landed_cost_amount', 18, 2)->default(0);
            $table->decimal('returned_quantity', 18, 3)->default(0);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['purchase_bill_id', 'line_no'], 'purchase_bill_lines_purchase_bill_id_line_no_unique');
            $table->index(['product_id'], 'purchase_bill_lines_product_id_index');
            $table->index(['tax_rule_id'], 'purchase_bill_lines_tax_rule_id_index');
            $table->index(['debit_ledger_id'], 'purchase_bill_lines_debit_ledger_id_index');
            $table->index(['purchase_order_line_id'], 'purchase_bill_lines_purchase_order_line_id_index');

            $table->foreign('purchase_bill_id', 'purchase_bill_lines_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'purchase_bill_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('tax_rule_id', 'purchase_bill_lines_tax_rule_id_foreign')->references('id')->on('tax_rules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('debit_ledger_id', 'purchase_bill_lines_debit_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_order_line_id', 'purchase_bill_lines_purchase_order_line_id_foreign')->references('id')->on('purchase_order_lines')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `purchase_bill_lines`
                    ADD CONSTRAINT `purchase_bill_lines_rule1_check` CHECK (`quantity` > 0),
                    ADD CONSTRAINT `purchase_bill_lines_rule2_check` CHECK (`unit_cost` >= 0),
                    ADD CONSTRAINT `purchase_bill_lines_rule3_check` CHECK (`returned_quantity` <= `quantity`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_lines');
    }
};
