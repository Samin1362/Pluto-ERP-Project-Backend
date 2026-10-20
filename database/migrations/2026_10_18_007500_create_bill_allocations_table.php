<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `bill_allocations` (Accounting): migration 75 of 83, dependency level 9.
 * Applies a settlement line (receipt, payment, credit/debit note, advance) to an invoice or bill.
 * Depends on: companies, journal_lines, purchase_bills, sales_invoices, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_allocations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Applies a settlement line (receipt, payment, credit/debit note, advance) to an invoice or bill.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('journal_line_id')->comment('The party-ledger line that settles');
            $table->unsignedBigInteger('sales_invoice_id')->nullable();
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('allocated_on');
            $table->dateTime('reversed_at')->nullable()->comment('Set when the settling entry is reversed or the document cancelled');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id'], 'bill_allocations_company_id_index');
            $table->index(['sales_invoice_id'], 'bill_allocations_sales_invoice_id_index');
            $table->index(['purchase_bill_id'], 'bill_allocations_purchase_bill_id_index');
            $table->index(['journal_line_id'], 'bill_allocations_journal_line_id_index');
            $table->index(['created_by'], 'bill_allocations_created_by_index');
            $table->index(['updated_by'], 'bill_allocations_updated_by_index');

            $table->foreign('company_id', 'bill_allocations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_line_id', 'bill_allocations_journal_line_id_foreign')->references('id')->on('journal_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('sales_invoice_id', 'bill_allocations_sales_invoice_id_foreign')->references('id')->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'bill_allocations_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'bill_allocations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'bill_allocations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `bill_allocations`
                    ADD CONSTRAINT `bill_allocations_rule1_check` CHECK (`amount` > 0),
                    ADD CONSTRAINT `bill_allocations_rule2_check` CHECK ((`sales_invoice_id` IS NULL) <> (`purchase_bill_id` IS NULL))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_allocations');
    }
};
