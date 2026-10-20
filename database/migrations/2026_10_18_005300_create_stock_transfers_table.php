<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_transfers` (Inventory): migration 53 of 83, dependency level 5.
 * Move quantity between warehouses: Draft > Dispatched > Received (D11).
 * Depends on: companies, journal_entries, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Move quantity between warehouses: Draft > Dispatched > Received (D11).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('number', 30);
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->date('dispatch_date')->nullable();
            $table->date('received_date')->nullable();
            $table->string('vehicle', 60)->nullable();
            $table->string('driver', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft')->comment('One of: draft, dispatched, received, cancelled');
            $table->unsignedBigInteger('dispatch_entry_id')->nullable()->comment('Only when warehouses use different inventory ledgers');
            $table->unsignedBigInteger('receive_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'stock_transfers_company_id_number_unique');
            $table->unique(['dispatch_entry_id'], 'stock_transfers_dispatch_entry_id_unique');
            $table->unique(['receive_entry_id'], 'stock_transfers_receive_entry_id_unique');
            $table->index(['from_warehouse_id'], 'stock_transfers_from_warehouse_id_index');
            $table->index(['to_warehouse_id'], 'stock_transfers_to_warehouse_id_index');
            $table->index(['created_by'], 'stock_transfers_created_by_index');
            $table->index(['updated_by'], 'stock_transfers_updated_by_index');

            $table->foreign('company_id', 'stock_transfers_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('from_warehouse_id', 'stock_transfers_from_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('to_warehouse_id', 'stock_transfers_to_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('dispatch_entry_id', 'stock_transfers_dispatch_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('receive_entry_id', 'stock_transfers_receive_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'stock_transfers_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'stock_transfers_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `stock_transfers`
                    ADD CONSTRAINT `stock_transfers_status_check` CHECK (`status` IN ('draft', 'dispatched', 'received', 'cancelled')),
                    ADD CONSTRAINT `stock_transfers_rule1_check` CHECK (`from_warehouse_id` <> `to_warehouse_id`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
