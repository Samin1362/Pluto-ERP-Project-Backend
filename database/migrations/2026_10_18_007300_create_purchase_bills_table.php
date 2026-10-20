<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `purchase_bills` (Purchases): migration 73 of 83, dependency level 8.
 * Supplier bill (F3/F4) = goods receipt. Import mode links an LC and lot and carries a foreign currency.
 * Depends on: branches, companies, currencies, journal_entries, lc_shipments, letters_of_credit, parties, payment_terms, purchase_orders, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Supplier bill (F3/F4) = goods receipt. Import mode links an LC and lot and carries a foreign currency.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('BILL number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Supplier');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->string('supplier_bill_no', 60)->comment('Required; unique per supplier');
            $table->date('bill_date');
            $table->date('due_date');
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->comment('Receive into');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('letter_of_credit_id')->nullable();
            $table->unsignedBigInteger('lc_shipment_id')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 18, 6)->default(1)->comment('BDT per unit; 1 for BDT bills');
            $table->text('notes')->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->string('receipt_condition_note', 255)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0)->comment('Sum of quantity x price before discount');
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('rounding_adjustment', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0)->comment('Base currency (BDT)');
            $table->decimal('total_foreign', 18, 2)->nullable();
            $table->decimal('amount_settled', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, cancelled');
            $table->string('payment_status', 30)->default('unpaid')->comment('One of: unpaid, part_paid, paid');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('cancellation_entry_id')->nullable();
            $table->string('cancel_reason', 60)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'purchase_bills_company_id_number_unique');
            $table->unique(['company_id', 'party_id', 'supplier_bill_no'], 'purchase_bills_company_id_party_id_supplier_bill_no_unique');
            $table->unique(['journal_entry_id'], 'purchase_bills_journal_entry_id_unique');
            $table->unique(['cancellation_entry_id'], 'purchase_bills_cancellation_entry_id_unique');
            $table->index(['company_id', 'status', 'due_date'], 'purchase_bills_company_id_status_due_date_index');
            $table->index(['company_id', 'bill_date'], 'purchase_bills_company_id_bill_date_index');
            $table->index(['party_id', 'payment_status'], 'purchase_bills_party_id_payment_status_index');
            $table->index(['branch_id'], 'purchase_bills_branch_id_index');
            $table->index(['payment_term_id'], 'purchase_bills_payment_term_id_index');
            $table->index(['warehouse_id'], 'purchase_bills_warehouse_id_index');
            $table->index(['purchase_order_id'], 'purchase_bills_purchase_order_id_index');
            $table->index(['letter_of_credit_id'], 'purchase_bills_letter_of_credit_id_index');
            $table->index(['lc_shipment_id'], 'purchase_bills_lc_shipment_id_index');
            $table->index(['currency_code'], 'purchase_bills_currency_code_index');
            $table->index(['received_by_user_id'], 'purchase_bills_received_by_user_id_index');
            $table->index(['cancelled_by'], 'purchase_bills_cancelled_by_index');
            $table->index(['posted_by'], 'purchase_bills_posted_by_index');
            $table->index(['created_by'], 'purchase_bills_created_by_index');
            $table->index(['updated_by'], 'purchase_bills_updated_by_index');

            $table->foreign('company_id', 'purchase_bills_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'purchase_bills_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'purchase_bills_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payment_term_id', 'purchase_bills_payment_term_id_foreign')->references('id')->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'purchase_bills_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_order_id', 'purchase_bills_purchase_order_id_foreign')->references('id')->on('purchase_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'purchase_bills_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_shipment_id', 'purchase_bills_lc_shipment_id_foreign')->references('id')->on('lc_shipments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('currency_code', 'purchase_bills_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('received_by_user_id', 'purchase_bills_received_by_user_id_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'purchase_bills_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cancellation_entry_id', 'purchase_bills_cancellation_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cancelled_by', 'purchase_bills_cancelled_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('posted_by', 'purchase_bills_posted_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'purchase_bills_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'purchase_bills_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `purchase_bills`
                    ADD CONSTRAINT `purchase_bills_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled')),
                    ADD CONSTRAINT `purchase_bills_payment_status_check` CHECK (`payment_status` IN ('unpaid', 'part_paid', 'paid')),
                    ADD CONSTRAINT `purchase_bills_rule1_check` CHECK (`due_date` >= `bill_date`),
                    ADD CONSTRAINT `purchase_bills_rule2_check` CHECK (`exchange_rate` > 0),
                    ADD CONSTRAINT `purchase_bills_rule3_check` CHECK (`balance_due` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bills');
    }
};
