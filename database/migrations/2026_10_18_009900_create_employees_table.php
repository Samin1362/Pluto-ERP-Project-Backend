<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `employees` (Payroll): migration 99 of 103, dependency level 2.
 * A person the company pays a salary (PEP-520). Monthly gross = basic + house rent + medical + conveyance.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A person the company pays a salary (PEP-520). Monthly gross = basic + house rent + medical + conveyance.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('code', 20)->comment('EMP-0001, from the employee number series');
            $table->string('name', 150);
            $table->string('designation', 80)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('national_id', 30)->nullable();
            $table->date('joining_date');
            $table->decimal('basic_salary', 18, 2);
            $table->decimal('house_rent', 18, 2)->default(0);
            $table->decimal('medical', 18, 2)->default(0);
            $table->decimal('conveyance', 18, 2)->default(0);
            $table->string('pay_method', 30)->default('cash')->comment('One of: cash, bank, mobile');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_no', 40)->nullable();
            $table->string('status', 30)->default('active')->comment('One of: active, left');
            $table->date('left_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'code'], 'employees_company_id_code_unique');
            $table->index(['company_id', 'status'], 'employees_company_id_status_index');
            $table->index(['created_by'], 'employees_created_by_index');
            $table->index(['updated_by'], 'employees_updated_by_index');

            $table->foreign('company_id', 'employees_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'employees_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'employees_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `employees`
                    ADD CONSTRAINT `employees_pay_method_check` CHECK (`pay_method` IN ('cash', 'bank', 'mobile')),
                    ADD CONSTRAINT `employees_status_check` CHECK (`status` IN ('active', 'left')),
                    ADD CONSTRAINT `employees_rule1_check` CHECK (`basic_salary` >= 0),
                    ADD CONSTRAINT `employees_rule2_check` CHECK (`house_rent` >= 0),
                    ADD CONSTRAINT `employees_rule3_check` CHECK (`medical` >= 0),
                    ADD CONSTRAINT `employees_rule4_check` CHECK (`conveyance` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
