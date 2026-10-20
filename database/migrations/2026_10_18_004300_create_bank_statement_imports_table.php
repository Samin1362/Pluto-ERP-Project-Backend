<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `bank_statement_imports` (Money): migration 43 of 83, dependency level 5.
 * An imported CSV/Excel statement file.
 * Depends on: companies, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('An imported CSV/Excel statement file.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('money_account_id');
            $table->string('original_name', 255);
            $table->string('file_path', 255);
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('line_count');
            $table->char('file_sha256', 64)->comment('Rejects re-import of the same file');
            $table->string('status', 30)->default('to_match')->comment('One of: to_match, reconciled');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['money_account_id', 'file_sha256'], 'bank_statement_imports_money_account_id_file_sha256_unique');
            $table->index(['company_id'], 'bank_statement_imports_company_id_index');
            $table->index(['created_by'], 'bank_statement_imports_created_by_index');
            $table->index(['updated_by'], 'bank_statement_imports_updated_by_index');

            $table->foreign('company_id', 'bank_statement_imports_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'bank_statement_imports_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'bank_statement_imports_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'bank_statement_imports_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `bank_statement_imports`
                    ADD CONSTRAINT `bank_statement_imports_status_check` CHECK (`status` IN ('to_match', 'reconciled'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
