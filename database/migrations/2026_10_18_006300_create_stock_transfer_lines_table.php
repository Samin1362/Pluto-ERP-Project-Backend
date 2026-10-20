<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_transfer_lines` (Inventory): migration 63 of 83, dependency level 6.
 * Products and quantities in a transfer.
 * Depends on: products, stock_transfers.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Products and quantities in a transfer.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4)->nullable()->comment('Average cost frozen at dispatch');
            $table->decimal('received_quantity', 18, 3)->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['stock_transfer_id', 'line_no'], 'stock_transfer_lines_stock_transfer_id_line_no_unique');
            $table->index(['product_id'], 'stock_transfer_lines_product_id_index');

            $table->foreign('stock_transfer_id', 'stock_transfer_lines_stock_transfer_id_foreign')->references('id')->on('stock_transfers')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('product_id', 'stock_transfer_lines_product_id_foreign')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `stock_transfer_lines`
                    ADD CONSTRAINT `stock_transfer_lines_rule1_check` CHECK (`quantity` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
    }
};
