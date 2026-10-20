<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_adjustment_lines` (Inventory): migration 60 of 83, dependency level 6.
 * Counted vs system quantity per product.
 * Depends on: products, stock_adjustments.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Counted vs system quantity per product.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->decimal('system_qty', 18, 3);
            $table->decimal('counted_qty', 18, 3);
            $table->decimal('difference_qty', 18, 3)->comment('counted - system (signed)');
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('value', 18, 2)->comment('Signed');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['stock_adjustment_id', 'line_no'], 'stock_adjustment_lines_stock_adjustment_id_line_no_unique');
            $table->index(['product_id'], 'stock_adjustment_lines_product_id_index');

            $table->foreign('stock_adjustment_id', 'stock_adjustment_lines_stock_adjustment_id_foreign')->references('id')->on('stock_adjustments')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'stock_adjustment_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
};
