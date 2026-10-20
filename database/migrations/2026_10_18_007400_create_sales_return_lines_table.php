<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `sales_return_lines` (Sales): migration 74 of 83, dependency level 8.
 * Returned quantity per original invoice line.
 * Depends on: products, sales_invoice_lines, sales_returns.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Returned quantity per original invoice line.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('sales_invoice_line_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('value', 18, 2);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->string('reason', 30)->comment('One of: expired, damaged_in_transit, leaking, wrong_item, not_needed');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['sales_return_id', 'line_no'], 'sales_return_lines_sales_return_id_line_no_unique');
            $table->index(['sales_invoice_line_id'], 'sales_return_lines_sales_invoice_line_id_index');
            $table->index(['product_id'], 'sales_return_lines_product_id_index');

            $table->foreign('sales_return_id', 'sales_return_lines_sales_return_id_foreign')->references('id')->on('sales_returns')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('sales_invoice_line_id', 'sales_return_lines_sales_invoice_line_id_foreign')->references('id')->on('sales_invoice_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'sales_return_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `sales_return_lines`
                    ADD CONSTRAINT `sales_return_lines_reason_check` CHECK (`reason` IN ('expired', 'damaged_in_transit', 'leaking', 'wrong_item', 'not_needed')),
                    ADD CONSTRAINT `sales_return_lines_rule1_check` CHECK (`quantity` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
    }
};
