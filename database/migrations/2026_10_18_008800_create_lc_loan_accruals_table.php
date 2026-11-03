<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_loan_accruals` (LC): migration 88 of 90, dependency level 11.
 * Month-end interest accrued on an open loan (Dr interest expense / Cr interest payable), cleared by the next repayment.
 * Depends on: companies, journal_entries, lc_loans, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_loan_accruals', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Month-end interest accrued on an open loan (Dr interest expense / Cr interest payable), cleared by the next repayment.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('lc_loan_id');
            $table->date('accrual_date');
            $table->date('interest_from');
            $table->unsignedSmallInteger('days');
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('posted')->comment('One of: posted, paid, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['lc_loan_id', 'accrual_date'], 'lc_loan_accruals_lc_loan_id_accrual_date_unique');
            $table->unique(['journal_entry_id'], 'lc_loan_accruals_journal_entry_id_unique');
            $table->index(['company_id'], 'lc_loan_accruals_company_id_index');
            $table->index(['created_by'], 'lc_loan_accruals_created_by_index');
            $table->index(['updated_by'], 'lc_loan_accruals_updated_by_index');

            $table->foreign('company_id', 'lc_loan_accruals_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_loan_id', 'lc_loan_accruals_lc_loan_id_foreign')->references('id')->on('lc_loans')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'lc_loan_accruals_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_loan_accruals_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_loan_accruals_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_loan_accruals`
                    ADD CONSTRAINT `lc_loan_accruals_status_check` CHECK (`status` IN ('posted', 'paid', 'reversed')),
                    ADD CONSTRAINT `lc_loan_accruals_rule1_check` CHECK (`amount` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_loan_accruals');
    }
};
