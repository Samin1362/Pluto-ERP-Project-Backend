<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_loan_repayments` (LC): migration 89 of 91, dependency level 11.
 * A repayment of a PAD/LTR loan: principal and the interest due to that date.
 * Depends on: companies, journal_entries, lc_loans, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_loan_repayments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A repayment of a PAD/LTR loan: principal and the interest due to that date.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('lc_loan_id');
            $table->date('repayment_date');
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0)->comment('Interest for days since interest_from (to 6601)');
            $table->decimal('accrued_cleared', 18, 2)->default(0)->comment('Interest accrued at month ends and paid now (from 2304)');
            $table->date('interest_from');
            $table->unsignedSmallInteger('interest_days')->default(0);
            $table->unsignedBigInteger('money_account_id')->comment('Paid from');
            $table->string('reference', 60)->nullable();
            $table->string('status', 30)->default('posted')->comment('One of: posted, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'lc_loan_repayments_journal_entry_id_unique');
            $table->index(['company_id'], 'lc_loan_repayments_company_id_index');
            $table->index(['lc_loan_id', 'status'], 'lc_loan_repayments_lc_loan_id_status_index');
            $table->index(['money_account_id'], 'lc_loan_repayments_money_account_id_index');
            $table->index(['created_by'], 'lc_loan_repayments_created_by_index');
            $table->index(['updated_by'], 'lc_loan_repayments_updated_by_index');

            $table->foreign('company_id', 'lc_loan_repayments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_loan_id', 'lc_loan_repayments_lc_loan_id_foreign')->references('id')->on('lc_loans')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'lc_loan_repayments_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'lc_loan_repayments_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_loan_repayments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_loan_repayments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_loan_repayments`
                    ADD CONSTRAINT `lc_loan_repayments_status_check` CHECK (`status` IN ('posted', 'reversed')),
                    ADD CONSTRAINT `lc_loan_repayments_rule1_check` CHECK (`principal_amount` >= 0),
                    ADD CONSTRAINT `lc_loan_repayments_rule2_check` CHECK (`interest_amount` >= 0),
                    ADD CONSTRAINT `lc_loan_repayments_rule3_check` CHECK (`accrued_cleared` >= 0),
                    ADD CONSTRAINT `lc_loan_repayments_rule4_check` CHECK (`principal_amount` + `interest_amount` + `accrued_cleared` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_loan_repayments');
    }
};
