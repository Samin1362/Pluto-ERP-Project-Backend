<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `bank_reconciliations` (Money): migration 42 of 83, dependency level 5.
 * One reconciliation session per bank account and statement date.
 * Depends on: companies, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('One reconciliation session per bank account and statement date.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('money_account_id');
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('book_balance', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->string('status', 30)->default('in_progress')->comment('One of: in_progress, finished');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('finished_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['money_account_id', 'statement_date'], 'bank_reconciliations_money_account_id_statement_date_unique');
            $table->index(['company_id'], 'bank_reconciliations_company_id_index');
            $table->index(['finished_by'], 'bank_reconciliations_finished_by_index');
            $table->index(['created_by'], 'bank_reconciliations_created_by_index');
            $table->index(['updated_by'], 'bank_reconciliations_updated_by_index');

            $table->foreign('company_id', 'bank_reconciliations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'bank_reconciliations_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('finished_by', 'bank_reconciliations_finished_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'bank_reconciliations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'bank_reconciliations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `bank_reconciliations`
                    ADD CONSTRAINT `bank_reconciliations_status_check` CHECK (`status` IN ('in_progress', 'finished')),
                    ADD CONSTRAINT `bank_reconciliations_rule1_check` CHECK (`status` <> 'finished' OR `difference` = 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
