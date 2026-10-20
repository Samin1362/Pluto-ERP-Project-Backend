<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `sales_invoices` (Sales): migration 59 of 83, dependency level 6.
 * Sales invoice (E3-E6). Posting moves stock out and creates its journal entry.
 * Depends on: branches, companies, journal_entries, parties, payment_terms, quotations, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Sales invoice (E3-E6). Posting moves stock out and creates its journal entry.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('INV number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Customer');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->string('customer_po_ref', 60)->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->comment('Dispatch from');
            $table->unsignedBigInteger('quotation_id')->nullable()->comment('Created from quotation');
            $table->text('notes')->nullable();
            $table->text('internal_note')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0)->comment('Sum of quantity x price before discount');
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('rounding_adjustment', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0)->comment('Base currency (BDT)');
            $table->decimal('amount_settled', 18, 2)->default(0)->comment('Sum of live bill_allocations');
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, cancelled');
            $table->string('payment_status', 30)->default('unpaid')->comment('One of: unpaid, part_paid, paid');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('cancellation_entry_id')->nullable();
            $table->string('cancel_reason', 60)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('challan_no', 30)->nullable();
            $table->string('vehicle_no', 40)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'sales_invoices_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'sales_invoices_journal_entry_id_unique');
            $table->unique(['cancellation_entry_id'], 'sales_invoices_cancellation_entry_id_unique');
            $table->index(['company_id', 'status', 'due_date'], 'sales_invoices_company_id_status_due_date_index');
            $table->index(['company_id', 'invoice_date'], 'sales_invoices_company_id_invoice_date_index');
            $table->index(['party_id', 'payment_status'], 'sales_invoices_party_id_payment_status_index');
            $table->index(['branch_id'], 'sales_invoices_branch_id_index');
            $table->index(['payment_term_id'], 'sales_invoices_payment_term_id_index');
            $table->index(['warehouse_id'], 'sales_invoices_warehouse_id_index');
            $table->index(['quotation_id'], 'sales_invoices_quotation_id_index');
            $table->index(['cancelled_by'], 'sales_invoices_cancelled_by_index');
            $table->index(['posted_by'], 'sales_invoices_posted_by_index');
            $table->index(['created_by'], 'sales_invoices_created_by_index');
            $table->index(['updated_by'], 'sales_invoices_updated_by_index');

            $table->foreign('company_id', 'sales_invoices_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'sales_invoices_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'sales_invoices_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payment_term_id', 'sales_invoices_payment_term_id_foreign')->references('id')->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'sales_invoices_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('quotation_id', 'sales_invoices_quotation_id_foreign')->references('id')->on('quotations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'sales_invoices_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cancellation_entry_id', 'sales_invoices_cancellation_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cancelled_by', 'sales_invoices_cancelled_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('posted_by', 'sales_invoices_posted_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'sales_invoices_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'sales_invoices_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `sales_invoices`
                    ADD CONSTRAINT `sales_invoices_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled')),
                    ADD CONSTRAINT `sales_invoices_payment_status_check` CHECK (`payment_status` IN ('unpaid', 'part_paid', 'paid')),
                    ADD CONSTRAINT `sales_invoices_rule1_check` CHECK (`due_date` >= `invoice_date`),
                    ADD CONSTRAINT `sales_invoices_rule2_check` CHECK (`balance_due` >= 0),
                    ADD CONSTRAINT `sales_invoices_rule3_check` CHECK (`total` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
