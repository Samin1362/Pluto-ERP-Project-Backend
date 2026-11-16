<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payroll_payment_lines` (Payroll): migration 103 of 103, dependency level 7.
 * How much of a salary payment went to each employee (PEP-522).
 * Depends on: payroll_lines, payroll_payments.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payment_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('How much of a salary payment went to each employee (PEP-522).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('payroll_payment_id');
            $table->unsignedBigInteger('payroll_line_id');
            $table->decimal('amount', 18, 2);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['payroll_payment_id', 'payroll_line_id'], 'payroll_payment_lines_payroll_payment_id_payroll_line_id_unique');
            $table->index(['payroll_line_id'], 'payroll_payment_lines_payroll_line_id_index');

            $table->foreign('payroll_payment_id', 'payroll_payment_lines_payroll_payment_id_foreign')->references('id')->on('payroll_payments')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('payroll_line_id', 'payroll_payment_lines_payroll_line_id_foreign')->references('id')->on('payroll_lines')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payroll_payment_lines`
                    ADD CONSTRAINT `payroll_payment_lines_rule1_check` CHECK (`amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_lines');
    }
};
