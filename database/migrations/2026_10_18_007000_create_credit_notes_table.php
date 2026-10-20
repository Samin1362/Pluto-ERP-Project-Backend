<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `credit_notes` (Sales): migration 70 of 83, dependency level 8.
 * Reduces what a customer owes (E9): applied to an invoice, kept as credit, or refunded.
 * Depends on: branches, companies, journal_entries, parties, sales_invoices, sales_returns, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Reduces what a customer owes (E9): applied to an invoice, kept as credit, or refunded.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('CN number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Customer');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->unsignedBigInteger('sales_return_id')->nullable();
            $table->unsignedBigInteger('sales_invoice_id')->nullable()->comment('Invoice it was created against');
            $table->date('note_date');
            $table->string('reason', 30)->comment('One of: goods_returned, price_correction, damaged_on_delivery, goodwill');
            $table->decimal('amount', 18, 2);
            $table->string('usage', 30)->comment('One of: apply, credit, refund');
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, cancelled');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'credit_notes_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'credit_notes_journal_entry_id_unique');
            $table->unique(['sales_return_id'], 'credit_notes_sales_return_id_unique');
            $table->index(['branch_id'], 'credit_notes_branch_id_index');
            $table->index(['party_id'], 'credit_notes_party_id_index');
            $table->index(['sales_invoice_id'], 'credit_notes_sales_invoice_id_index');
            $table->index(['created_by'], 'credit_notes_created_by_index');
            $table->index(['updated_by'], 'credit_notes_updated_by_index');

            $table->foreign('company_id', 'credit_notes_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'credit_notes_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'credit_notes_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('sales_return_id', 'credit_notes_sales_return_id_foreign')->references('id')->on('sales_returns')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('sales_invoice_id', 'credit_notes_sales_invoice_id_foreign')->references('id')->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'credit_notes_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'credit_notes_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'credit_notes_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `credit_notes`
                    ADD CONSTRAINT `credit_notes_reason_check` CHECK (`reason` IN ('goods_returned', 'price_correction', 'damaged_on_delivery', 'goodwill')),
                    ADD CONSTRAINT `credit_notes_usage_check` CHECK (`usage` IN ('apply', 'credit', 'refund')),
                    ADD CONSTRAINT `credit_notes_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled')),
                    ADD CONSTRAINT `credit_notes_rule1_check` CHECK (`amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
