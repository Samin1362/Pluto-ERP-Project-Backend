<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `fx_revaluations` (LC): migration 85 of 90, dependency level 5.
 * Period-end revaluation of open foreign-currency items at closing rates; reversed automatically the next day.
 * Depends on: companies, journal_entries, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Period-end revaluation of open foreign-currency items at closing rates; reversed automatically the next day.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->date('revaluation_date');
            $table->date('reversal_date');
            $table->json('rates')->comment('{currency: closing rate}');
            $table->decimal('total_difference', 18, 2)->comment('Signed: + loss, - gain');
            $table->string('status', 30)->default('posted')->comment('One of: posted, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_entry_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'fx_revaluations_journal_entry_id_unique');
            $table->unique(['reversal_entry_id'], 'fx_revaluations_reversal_entry_id_unique');
            $table->index(['company_id', 'revaluation_date'], 'fx_revaluations_company_id_revaluation_date_index');
            $table->index(['created_by'], 'fx_revaluations_created_by_index');
            $table->index(['updated_by'], 'fx_revaluations_updated_by_index');

            $table->foreign('company_id', 'fx_revaluations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'fx_revaluations_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reversal_entry_id', 'fx_revaluations_reversal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fx_revaluations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'fx_revaluations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `fx_revaluations`
                    ADD CONSTRAINT `fx_revaluations_status_check` CHECK (`status` IN ('posted', 'reversed'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluations');
    }
};
