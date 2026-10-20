<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `sales_returns` (Sales): migration 68 of 83, dependency level 7.
 * Goods coming back from a customer against an invoice (E8).
 * Depends on: branches, companies, journal_entries, parties, sales_invoices, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Goods coming back from a customer against an invoice (E8).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('SR number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Customer of the original invoice');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->unsignedBigInteger('sales_invoice_id');
            $table->date('return_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('stock_action', 30)->comment('One of: restock, write_off');
            $table->text('notes')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, cancelled');
            $table->unsignedBigInteger('journal_entry_id')->nullable()->comment('Stock part (restock only)');
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'sales_returns_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'sales_returns_journal_entry_id_unique');
            $table->index(['branch_id'], 'sales_returns_branch_id_index');
            $table->index(['party_id'], 'sales_returns_party_id_index');
            $table->index(['sales_invoice_id'], 'sales_returns_sales_invoice_id_index');
            $table->index(['warehouse_id'], 'sales_returns_warehouse_id_index');
            $table->index(['created_by'], 'sales_returns_created_by_index');
            $table->index(['updated_by'], 'sales_returns_updated_by_index');

            $table->foreign('company_id', 'sales_returns_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'sales_returns_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'sales_returns_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('sales_invoice_id', 'sales_returns_sales_invoice_id_foreign')->references('id')->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'sales_returns_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'sales_returns_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'sales_returns_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'sales_returns_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `sales_returns`
                    ADD CONSTRAINT `sales_returns_stock_action_check` CHECK (`stock_action` IN ('restock', 'write_off')),
                    ADD CONSTRAINT `sales_returns_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
