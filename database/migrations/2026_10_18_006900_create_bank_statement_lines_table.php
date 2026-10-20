<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `bank_statement_lines` (Money): migration 69 of 83, dependency level 8.
 * A statement line and how it was matched to the books.
 * Depends on: bank_reconciliations, bank_statement_imports, companies, journal_lines, money_accounts, users. Self-reference: paired_line_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A statement line and how it was matched to the books.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('bank_statement_import_id');
            $table->unsignedBigInteger('money_account_id');
            $table->unsignedInteger('line_no');
            $table->date('txn_date');
            $table->string('description', 255);
            $table->string('bank_reference', 80)->nullable();
            $table->decimal('amount', 18, 2)->comment('Signed: + money in, - money out');
            $table->string('status', 30)->default('unmatched')->comment('One of: unmatched, matched, created, paired, ignored');
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->unsignedBigInteger('paired_line_id')->nullable();
            $table->unsignedBigInteger('bank_reconciliation_id')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->dateTime('matched_at')->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['bank_statement_import_id', 'line_no'], 'bank_statement_lines_bank_statement_import_id_line_no_unique');
            $table->unique(['matched_journal_line_id'], 'bank_statement_lines_matched_journal_line_id_unique');
            $table->index(['company_id', 'money_account_id', 'status'], 'bank_statement_lines_company_id_money_account_id_status_index');
            $table->index(['money_account_id'], 'bank_statement_lines_money_account_id_index');
            $table->index(['paired_line_id'], 'bank_statement_lines_paired_line_id_index');
            $table->index(['bank_reconciliation_id'], 'bank_statement_lines_bank_reconciliation_id_index');
            $table->index(['matched_by'], 'bank_statement_lines_matched_by_index');

            $table->foreign('company_id', 'bank_statement_lines_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('bank_statement_import_id', 'bank_statement_lines_bank_statement_import_id_foreign')->references('id')->on('bank_statement_imports')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'bank_statement_lines_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('matched_journal_line_id', 'bank_statement_lines_matched_journal_line_id_foreign')->references('id')->on('journal_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('paired_line_id', 'bank_statement_lines_paired_line_id_foreign')->references('id')->on('bank_statement_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('bank_reconciliation_id', 'bank_statement_lines_bank_reconciliation_id_foreign')->references('id')->on('bank_reconciliations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('matched_by', 'bank_statement_lines_matched_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `bank_statement_lines`
                    ADD CONSTRAINT `bank_statement_lines_status_check` CHECK (`status` IN ('unmatched', 'matched', 'created', 'paired', 'ignored'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
