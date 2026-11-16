<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `staff_advances` (Payroll): migration 101 of 105, dependency level 5.
 * Money given to an employee before payday (PEP-523): Dr staff_advances / Cr the money account; recovered from salary sheets, an instalment a month.
 * Depends on: companies, employees, journal_entries, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advances', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Money given to an employee before payday (PEP-523): Dr staff_advances / Cr the money account; recovered from salary sheets, an instalment a month.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('money_account_id');
            $table->date('given_on');
            $table->decimal('amount', 18, 2);
            $table->decimal('instalment', 18, 2)->comment('Taken back each month');
            $table->decimal('recovered', 18, 2)->default(0)->comment('Taken back by approved salary sheets so far');
            $table->string('status', 30)->default('open')->comment('One of: open, settled, reversed');
            $table->string('reference', 60)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->comment('Set in the same transaction as the advance');
            $table->unsignedBigInteger('reversal_entry_id')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->string('reverse_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'staff_advances_journal_entry_id_unique');
            $table->unique(['reversal_entry_id'], 'staff_advances_reversal_entry_id_unique');
            $table->index(['company_id', 'employee_id', 'status'], 'staff_advances_company_id_employee_id_status_index');
            $table->index(['employee_id'], 'staff_advances_employee_id_index');
            $table->index(['money_account_id'], 'staff_advances_money_account_id_index');
            $table->index(['created_by'], 'staff_advances_created_by_index');
            $table->index(['updated_by'], 'staff_advances_updated_by_index');

            $table->foreign('company_id', 'staff_advances_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('employee_id', 'staff_advances_employee_id_foreign')->references('id')->on('employees')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'staff_advances_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'staff_advances_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reversal_entry_id', 'staff_advances_reversal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'staff_advances_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'staff_advances_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `staff_advances`
                    ADD CONSTRAINT `staff_advances_status_check` CHECK (`status` IN ('open', 'settled', 'reversed')),
                    ADD CONSTRAINT `staff_advances_rule1_check` CHECK (`amount` > 0),
                    ADD CONSTRAINT `staff_advances_rule2_check` CHECK (`instalment` > 0),
                    ADD CONSTRAINT `staff_advances_rule3_check` CHECK (`recovered` >= 0),
                    ADD CONSTRAINT `staff_advances_rule4_check` CHECK (`recovered` <= `amount`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advances');
    }
};
