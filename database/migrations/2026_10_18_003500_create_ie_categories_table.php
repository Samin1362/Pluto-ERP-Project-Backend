<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `ie_categories` (Money): migration 35 of 83, dependency level 4.
 * Income or expense category with its own ledger and optional monthly budget (I7).
 * Depends on: companies, ledgers, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ie_categories', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Income or expense category with its own ledger and optional monthly budget (I7).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('kind', 30)->comment('One of: income, expense');
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('ledger_id');
            $table->decimal('monthly_budget', 18, 2)->nullable();
            $table->decimal('warn_at_percent', 7, 4)->default(90);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'kind', 'name'], 'ie_categories_company_id_kind_name_unique');
            $table->unique(['ledger_id'], 'ie_categories_ledger_id_unique');
            $table->index(['created_by'], 'ie_categories_created_by_index');
            $table->index(['updated_by'], 'ie_categories_updated_by_index');

            $table->foreign('company_id', 'ie_categories_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'ie_categories_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'ie_categories_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'ie_categories_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `ie_categories`
                    ADD CONSTRAINT `ie_categories_kind_check` CHECK (`kind` IN ('income', 'expense'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ie_categories');
    }
};
