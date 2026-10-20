<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `journal_lines` (Accounting): migration 64 of 83, dependency level 7.
 * Debit or credit line of a journal entry. Posted lines are immutable.
 * Depends on: companies, currencies, journal_entries, ledgers, letters_of_credit, tax_rules, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Debit or credit line of a journal entry. Posted lines are immutable.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('journal_entry_id')->comment('CASCADE only matters for drafts; posted entries are never deleted');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('ledger_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('line_narration', 500)->nullable();
            $table->string('against_reference', 60)->nullable();
            $table->unsignedBigInteger('tax_rule_id')->nullable()->comment('RESTRICT: posted lines are immutable');
            $table->char('foreign_currency_code', 3)->nullable();
            $table->decimal('foreign_amount', 18, 2)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('letter_of_credit_id')->nullable()->comment('Tags LC clearing balances');
            $table->date('entry_date')->comment('Copy of header date for ledger reports');
            $table->boolean('is_posted')->default(false)->comment('Copy of header status = posted/reversed');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id', 'line_no'], 'journal_lines_journal_entry_id_line_no_unique');
            $table->index(['company_id', 'ledger_id', 'is_posted', 'entry_date'], 'journal_lines_company_id_ledger_id_is_posted_entry_date_index');
            $table->index(['letter_of_credit_id'], 'journal_lines_letter_of_credit_id_index');
            $table->index(['ledger_id'], 'journal_lines_ledger_id_index');
            $table->index(['tax_rule_id'], 'journal_lines_tax_rule_id_index');
            $table->index(['foreign_currency_code'], 'journal_lines_foreign_currency_code_index');
            $table->index(['warehouse_id'], 'journal_lines_warehouse_id_index');

            $table->foreign('company_id', 'journal_lines_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'journal_lines_journal_entry_id_foreign')->references('id')->on('journal_entries')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'journal_lines_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('tax_rule_id', 'journal_lines_tax_rule_id_foreign')->references('id')->on('tax_rules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('foreign_currency_code', 'journal_lines_foreign_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'journal_lines_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'journal_lines_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `journal_lines`
                    ADD CONSTRAINT `journal_lines_rule1_check` CHECK (`debit` >= 0 AND `credit` >= 0),
                    ADD CONSTRAINT `journal_lines_rule2_check` CHECK ((`debit` = 0) <> (`credit` = 0))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
