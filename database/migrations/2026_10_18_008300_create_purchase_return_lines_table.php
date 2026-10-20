<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `purchase_return_lines` (Purchases): migration 83 of 83, dependency level 10.
 * Returned quantity per original bill line.
 * Depends on: products, purchase_bill_lines, purchase_returns.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Returned quantity per original bill line.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('purchase_bill_line_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4)->comment('Original bill cost in BDT');
            $table->decimal('value', 18, 2);
            $table->string('reason', 30)->comment('One of: leaking, broken, short_supply, stale_stock, torn_packs, quality_claim');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['purchase_return_id', 'line_no'], 'purchase_return_lines_purchase_return_id_line_no_unique');
            $table->index(['purchase_bill_line_id'], 'purchase_return_lines_purchase_bill_line_id_index');
            $table->index(['product_id'], 'purchase_return_lines_product_id_index');

            $table->foreign('purchase_return_id', 'purchase_return_lines_purchase_return_id_foreign')->references('id')->on('purchase_returns')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_line_id', 'purchase_return_lines_purchase_bill_line_id_foreign')->references('id')->on('purchase_bill_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'purchase_return_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `purchase_return_lines`
                    ADD CONSTRAINT `purchase_return_lines_reason_check` CHECK (`reason` IN ('leaking', 'broken', 'short_supply', 'stale_stock', 'torn_packs', 'quality_claim')),
                    ADD CONSTRAINT `purchase_return_lines_rule1_check` CHECK (`quantity` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
