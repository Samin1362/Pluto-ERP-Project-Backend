<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `fiscal_years` (Accounting): migration 14 of 83, dependency level 2.
 * Financial year (FY 2026-27 = 1 Jul 2026 - 30 Jun 2027).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Financial year (FY 2026-27 = 1 Jul 2026 - 30 Jun 2027).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('open')->comment('One of: open, closing, closed');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'start_date'], 'fiscal_years_company_id_start_date_unique');
            $table->unique(['company_id', 'name'], 'fiscal_years_company_id_name_unique');
            $table->index(['closed_by'], 'fiscal_years_closed_by_index');
            $table->index(['created_by'], 'fiscal_years_created_by_index');
            $table->index(['updated_by'], 'fiscal_years_updated_by_index');

            $table->foreign('company_id', 'fiscal_years_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('closed_by', 'fiscal_years_closed_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fiscal_years_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'fiscal_years_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `fiscal_years`
                    ADD CONSTRAINT `fiscal_years_status_check` CHECK (`status` IN ('open', 'closing', 'closed')),
                    ADD CONSTRAINT `fiscal_years_rule1_check` CHECK (`end_date` > `start_date`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
