<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `staff_advance_recoveries` (Payroll): migration 105 of 105, dependency level 7.
 * Which advance a salary sheet line took back, and how much (PEP-523); removed when the sheet is cancelled.
 * Depends on: payroll_lines, staff_advances.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advance_recoveries', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Which advance a salary sheet line took back, and how much (PEP-523); removed when the sheet is cancelled.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('staff_advance_id');
            $table->unsignedBigInteger('payroll_line_id');
            $table->decimal('amount', 18, 2);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['staff_advance_id', 'payroll_line_id'], 'staff_advance_recoveries_staff_advance_id_payroll_line_id_unique');
            $table->index(['payroll_line_id'], 'staff_advance_recoveries_payroll_line_id_index');

            $table->foreign('staff_advance_id', 'staff_advance_recoveries_staff_advance_id_foreign')->references('id')->on('staff_advances')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('payroll_line_id', 'staff_advance_recoveries_payroll_line_id_foreign')->references('id')->on('payroll_lines')->cascadeOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `staff_advance_recoveries`
                    ADD CONSTRAINT `staff_advance_recoveries_rule1_check` CHECK (`amount` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advance_recoveries');
    }
};
