<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `accounting_periods` (Accounting): migration 25 of 83, dependency level 3.
 * Monthly posting period. Locked periods reject new, edited or reversed entries dated inside them.
 * Depends on: companies, fiscal_years, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Monthly posting period. Locked periods reject new, edited or reversed entries dated inside them.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('future')->comment('One of: future, open, locked');
            $table->dateTime('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->string('lock_note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'start_date'], 'accounting_periods_company_id_start_date_unique');
            $table->index(['company_id', 'status'], 'accounting_periods_company_id_status_index');
            $table->index(['fiscal_year_id'], 'accounting_periods_fiscal_year_id_index');
            $table->index(['locked_by'], 'accounting_periods_locked_by_index');
            $table->index(['created_by'], 'accounting_periods_created_by_index');
            $table->index(['updated_by'], 'accounting_periods_updated_by_index');

            $table->foreign('company_id', 'accounting_periods_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('fiscal_year_id', 'accounting_periods_fiscal_year_id_foreign')->references('id')->on('fiscal_years')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('locked_by', 'accounting_periods_locked_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'accounting_periods_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'accounting_periods_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `accounting_periods`
                    ADD CONSTRAINT `accounting_periods_status_check` CHECK (`status` IN ('future', 'open', 'locked')),
                    ADD CONSTRAINT `accounting_periods_rule1_check` CHECK (`end_date` >= `start_date`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
