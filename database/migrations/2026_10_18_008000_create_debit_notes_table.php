<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `debit_notes` (Purchases): migration 80 of 83, dependency level 10.
 * Reduces what you owe a supplier (F7).
 * Depends on: branches, companies, journal_entries, parties, purchase_bills, purchase_returns, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Reduces what you owe a supplier (F7).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('DN number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Supplier');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->unsignedBigInteger('purchase_return_id')->nullable();
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->date('note_date');
            $table->string('reason', 30)->comment('One of: goods_returned, short_supply, price_difference, quality_claim');
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

            $table->unique(['company_id', 'number'], 'debit_notes_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'debit_notes_journal_entry_id_unique');
            $table->unique(['purchase_return_id'], 'debit_notes_purchase_return_id_unique');
            $table->index(['branch_id'], 'debit_notes_branch_id_index');
            $table->index(['party_id'], 'debit_notes_party_id_index');
            $table->index(['purchase_bill_id'], 'debit_notes_purchase_bill_id_index');
            $table->index(['created_by'], 'debit_notes_created_by_index');
            $table->index(['updated_by'], 'debit_notes_updated_by_index');

            $table->foreign('company_id', 'debit_notes_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'debit_notes_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'debit_notes_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_return_id', 'debit_notes_purchase_return_id_foreign')->references('id')->on('purchase_returns')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'debit_notes_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'debit_notes_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'debit_notes_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'debit_notes_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `debit_notes`
                    ADD CONSTRAINT `debit_notes_reason_check` CHECK (`reason` IN ('goods_returned', 'short_supply', 'price_difference', 'quality_claim')),
                    ADD CONSTRAINT `debit_notes_usage_check` CHECK (`usage` IN ('apply', 'credit', 'refund')),
                    ADD CONSTRAINT `debit_notes_status_check` CHECK (`status` IN ('draft', 'posted', 'cancelled')),
                    ADD CONSTRAINT `debit_notes_rule1_check` CHECK (`amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
