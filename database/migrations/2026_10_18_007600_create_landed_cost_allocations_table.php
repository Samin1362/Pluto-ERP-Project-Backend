<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `landed_cost_allocations` (LC): migration 76 of 83, dependency level 9.
 * Spreads LC charges and import expenses over the goods of an import bill.
 * Depends on: companies, journal_entries, letters_of_credit, purchase_bills, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Spreads LC charges and import expenses over the goods of an import bill.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedBigInteger('purchase_bill_id');
            $table->date('allocation_date');
            $table->string('method', 30)->default('value')->comment('One of: value, quantity');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'landed_cost_allocations_journal_entry_id_unique');
            $table->index(['company_id'], 'landed_cost_allocations_company_id_index');
            $table->index(['letter_of_credit_id'], 'landed_cost_allocations_letter_of_credit_id_index');
            $table->index(['purchase_bill_id'], 'landed_cost_allocations_purchase_bill_id_index');
            $table->index(['created_by'], 'landed_cost_allocations_created_by_index');
            $table->index(['updated_by'], 'landed_cost_allocations_updated_by_index');

            $table->foreign('company_id', 'landed_cost_allocations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'landed_cost_allocations_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'landed_cost_allocations_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'landed_cost_allocations_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'landed_cost_allocations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'landed_cost_allocations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `landed_cost_allocations`
                    ADD CONSTRAINT `landed_cost_allocations_method_check` CHECK (`method` IN ('value', 'quantity')),
                    ADD CONSTRAINT `landed_cost_allocations_status_check` CHECK (`status` IN ('draft', 'posted', 'reversed')),
                    ADD CONSTRAINT `landed_cost_allocations_rule1_check` CHECK (`total_amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
    }
};
