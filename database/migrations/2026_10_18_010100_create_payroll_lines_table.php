<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payroll_lines` (Payroll): migration 101 of 103, dependency level 6.
 * One employee on a salary sheet (PEP-521): the salary structure for the days paid, plus overtime and other earnings, less deductions and advance recovery.
 * Depends on: employees, payroll_runs.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('One employee on a salary sheet (PEP-521): the salary structure for the days paid, plus overtime and other earnings, less deductions and advance recovery.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('employee_code', 20);
            $table->string('employee_name', 150);
            $table->string('designation', 80)->nullable();
            $table->unsignedTinyInteger('days_in_month');
            $table->unsignedTinyInteger('days_paid')->comment('Fewer when they joined or left in the month');
            $table->decimal('basic_salary', 18, 2);
            $table->decimal('house_rent', 18, 2)->default(0);
            $table->decimal('medical', 18, 2)->default(0);
            $table->decimal('conveyance', 18, 2)->default(0);
            $table->decimal('overtime', 18, 2)->default(0);
            $table->decimal('other_earnings', 18, 2)->default(0)->comment('Commission, incentive, arrears');
            $table->decimal('deductions', 18, 2)->default(0)->comment('Absence, fines, other');
            $table->decimal('advance_recovery', 18, 2)->default(0)->comment('Staff advance taken back this month (PEP-523)');
            $table->decimal('gross', 18, 2);
            $table->decimal('net', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0)->comment('Paid so far (PEP-522)');
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_lines_payroll_run_id_employee_id_unique');
            $table->index(['employee_id'], 'payroll_lines_employee_id_index');

            $table->foreign('payroll_run_id', 'payroll_lines_payroll_run_id_foreign')->references('id')->on('payroll_runs')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('employee_id', 'payroll_lines_employee_id_foreign')->references('id')->on('employees')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payroll_lines`
                    ADD CONSTRAINT `payroll_lines_rule1_check` CHECK (`net` >= 0),
                    ADD CONSTRAINT `payroll_lines_rule2_check` CHECK (`deductions` >= 0),
                    ADD CONSTRAINT `payroll_lines_rule3_check` CHECK (`overtime` >= 0),
                    ADD CONSTRAINT `payroll_lines_rule4_check` CHECK (`other_earnings` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
