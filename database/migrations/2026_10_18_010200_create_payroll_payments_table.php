<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payroll_payments` (Payroll): migration 102 of 103, dependency level 6.
 * Salaries paid from one money account on one day against an approved sheet (PEP-522): Dr salaries_payable / Cr the account; reversed by a mirror entry.
 * Depends on: companies, journal_entries, money_accounts, payroll_runs, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Salaries paid from one money account on one day against an approved sheet (PEP-522): Dr salaries_payable / Cr the account; reversed by a mirror entry.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('money_account_id');
            $table->date('paid_on');
            $table->decimal('amount', 18, 2);
            $table->unsignedSmallInteger('employees_count');
            $table->string('reference', 60)->nullable()->comment('Cheque, transfer or bKash reference');
            $table->string('status', 30)->default('posted')->comment('One of: posted, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable()->comment('Set in the same transaction as the payment');
            $table->unsignedBigInteger('reversal_entry_id')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->string('reverse_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'payroll_payments_journal_entry_id_unique');
            $table->unique(['reversal_entry_id'], 'payroll_payments_reversal_entry_id_unique');
            $table->index(['company_id', 'payroll_run_id'], 'payroll_payments_company_id_payroll_run_id_index');
            $table->index(['payroll_run_id'], 'payroll_payments_payroll_run_id_index');
            $table->index(['money_account_id'], 'payroll_payments_money_account_id_index');
            $table->index(['created_by'], 'payroll_payments_created_by_index');
            $table->index(['updated_by'], 'payroll_payments_updated_by_index');

            $table->foreign('company_id', 'payroll_payments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payroll_run_id', 'payroll_payments_payroll_run_id_foreign')->references('id')->on('payroll_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'payroll_payments_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'payroll_payments_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reversal_entry_id', 'payroll_payments_reversal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'payroll_payments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'payroll_payments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payroll_payments`
                    ADD CONSTRAINT `payroll_payments_status_check` CHECK (`status` IN ('posted', 'reversed')),
                    ADD CONSTRAINT `payroll_payments_rule1_check` CHECK (`amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payments');
    }
};
