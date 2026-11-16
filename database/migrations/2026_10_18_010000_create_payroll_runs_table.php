<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payroll_runs` (Payroll): migration 100 of 105, dependency level 5.
 * A salary sheet for one month (PEP-521): draft while it is prepared, approved when posted (Dr salaries_expense / Cr salaries_payable), cancelled by reversal.
 * Depends on: companies, journal_entries, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A salary sheet for one month (PEP-521): draft while it is prepared, approved when posted (Dr salaries_expense / Cr salaries_payable), cancelled by reversal.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('kind', 30)->default('salary')->comment('One of: salary, bonus. bonus: festival bonus sheets (PEP-524)');
            $table->date('period_month')->comment('First day of the month paid');
            $table->date('pay_date')->comment('Date of the salary entry; the month end by default');
            $table->string('status', 30)->default('draft')->comment('One of: draft, approved, cancelled');
            $table->unsignedSmallInteger('employees_count')->default(0);
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_entry_id')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'payroll_runs_journal_entry_id_unique');
            $table->unique(['reversal_entry_id'], 'payroll_runs_reversal_entry_id_unique');
            $table->index(['company_id', 'kind', 'period_month'], 'payroll_runs_company_id_kind_period_month_index');
            $table->index(['company_id', 'status'], 'payroll_runs_company_id_status_index');
            $table->index(['approved_by'], 'payroll_runs_approved_by_index');
            $table->index(['created_by'], 'payroll_runs_created_by_index');
            $table->index(['updated_by'], 'payroll_runs_updated_by_index');

            $table->foreign('company_id', 'payroll_runs_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'payroll_runs_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reversal_entry_id', 'payroll_runs_reversal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('approved_by', 'payroll_runs_approved_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'payroll_runs_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'payroll_runs_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payroll_runs`
                    ADD CONSTRAINT `payroll_runs_kind_check` CHECK (`kind` IN ('salary', 'bonus')),
                    ADD CONSTRAINT `payroll_runs_status_check` CHECK (`status` IN ('draft', 'approved', 'cancelled')),
                    ADD CONSTRAINT `payroll_runs_rule1_check` CHECK (`total_gross` >= 0),
                    ADD CONSTRAINT `payroll_runs_rule2_check` CHECK (`total_net` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
