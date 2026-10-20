<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `recurring_templates` (Money): migration 51 of 83, dependency level 5.
 * Repeating expense/income/journal; each run creates a draft for confirmation (never auto-posts).
 * Depends on: companies, journal_entries, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_templates', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Repeating expense/income/journal; each run creates a draft for confirmation (never auto-posts).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('kind', 30)->comment('One of: expense, income, journal');
            $table->string('frequency', 30)->comment('One of: weekly, monthly, quarterly, yearly');
            $table->date('next_run_on');
            $table->string('ends_type', 30)->comment('One of: never, after_count, on_date');
            $table->unsignedSmallInteger('ends_after_count')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('occurrences_done')->default(0);
            $table->json('payload')->comment('Validated draft body');
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'is_active', 'next_run_on'], 'recurring_templates_company_id_is_active_next_run_on_index');
            $table->index(['last_entry_id'], 'recurring_templates_last_entry_id_index');
            $table->index(['created_by'], 'recurring_templates_created_by_index');
            $table->index(['updated_by'], 'recurring_templates_updated_by_index');

            $table->foreign('company_id', 'recurring_templates_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('last_entry_id', 'recurring_templates_last_entry_id_foreign')->references('id')->on('journal_entries')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'recurring_templates_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'recurring_templates_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `recurring_templates`
                    ADD CONSTRAINT `recurring_templates_kind_check` CHECK (`kind` IN ('expense', 'income', 'journal')),
                    ADD CONSTRAINT `recurring_templates_frequency_check` CHECK (`frequency` IN ('weekly', 'monthly', 'quarterly', 'yearly')),
                    ADD CONSTRAINT `recurring_templates_ends_type_check` CHECK (`ends_type` IN ('never', 'after_count', 'on_date'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_templates');
    }
};
