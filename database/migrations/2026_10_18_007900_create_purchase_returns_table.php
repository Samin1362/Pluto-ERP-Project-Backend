<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `purchase_returns` (Purchases): migration 79 of 83, dependency level 9.
 * Goods going back to a supplier (F6).
 * Depends on: branches, companies, journal_entries, parties, purchase_bills, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Goods going back to a supplier (F6).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('PR number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Supplier of the original bill');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->unsignedBigInteger('purchase_bill_id');
            $table->date('return_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('stock_action', 30)->comment('One of: send_back, already_disposed');
            $table->text('notes')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, cancelled');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'purchase_returns_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'purchase_returns_journal_entry_id_unique');
            $table->index(['branch_id'], 'purchase_returns_branch_id_index');
            $table->index(['party_id'], 'purchase_returns_party_id_index');
            $table->index(['purchase_bill_id'], 'purchase_returns_purchase_bill_id_index');
            $table->index(['warehouse_id'], 'purchase_returns_warehouse_id_index');
            $table->index(['created_by'], 'purchase_returns_created_by_index');
            $table->index(['updated_by'], 'purchase_returns_updated_by_index');

            $table->foreign('company_id', 'purchase_returns_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'purchase_returns_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'purchase_returns_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'purchase_returns_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'purchase_returns_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'purchase_returns_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'purchase_returns_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'purchase_returns_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `purchase_returns`
                    ADD CONSTRAINT `purchase_returns_stock_action_check` CHECK (`stock_action` IN ('send_back', 'already_disposed')),
                    ADD CONSTRAINT `purchase_returns_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
