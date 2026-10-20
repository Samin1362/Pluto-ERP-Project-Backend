<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `number_sequences` (Accounting): migration 29 of 83, dependency level 3.
 * Document number series per company, document type and financial year (INV-2026-0588).
 * Depends on: companies, fiscal_years, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Document number series per company, document type and financial year (INV-2026-0588).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('fiscal_year_id')->nullable()->comment('NULL for never-reset series (CUS, SUP)');
            $table->string('document_type', 40);
            $table->string('prefix', 20);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('reset_policy', 30)->default('fiscal_year')->comment('One of: fiscal_year, never');
            $table->unsignedBigInteger('fy_scope')->storedAs('IFNULL(`fiscal_year_id`, 0)')->nullable(false)->comment('Generated: 0 for never-reset series, so the unique key also covers them (R-15)');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'fy_scope', 'document_type'], 'number_sequences_company_id_fy_scope_document_type_unique');
            $table->index(['fiscal_year_id'], 'number_sequences_fiscal_year_id_index');
            $table->index(['created_by'], 'number_sequences_created_by_index');
            $table->index(['updated_by'], 'number_sequences_updated_by_index');

            $table->foreign('company_id', 'number_sequences_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('fiscal_year_id', 'number_sequences_fiscal_year_id_foreign')->references('id')->on('fiscal_years')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'number_sequences_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'number_sequences_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `number_sequences`
                    ADD CONSTRAINT `number_sequences_reset_policy_check` CHECK (`reset_policy` IN ('fiscal_year', 'never')),
                    ADD CONSTRAINT `number_sequences_rule1_check` CHECK ((`reset_policy` = 'never') = (`fiscal_year_id` IS NULL)),
                    ADD CONSTRAINT `number_sequences_rule2_check` CHECK (`next_number` >= 1)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
