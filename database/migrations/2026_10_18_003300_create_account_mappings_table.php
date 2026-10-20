<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `account_mappings` (Accounting): migration 33 of 83, dependency level 4.
 * Configurable posting rules: maps a posting role (e.g. cogs, sales_returns) to a ledger or group. Replaces hard-coded account IDs.
 * Depends on: account_groups, companies, ledgers, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Configurable posting rules: maps a posting role (e.g. cogs, sales_returns) to a ledger or group. Replaces hard-coded account IDs.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('mapping_key', 60)->comment('See 06 section 3 for the full list');
            $table->unsignedBigInteger('ledger_id')->nullable();
            $table->unsignedBigInteger('account_group_id')->nullable()->comment('For group mappings such as sundry_debtors');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'mapping_key'], 'account_mappings_company_id_mapping_key_unique');
            $table->index(['ledger_id'], 'account_mappings_ledger_id_index');
            $table->index(['account_group_id'], 'account_mappings_account_group_id_index');
            $table->index(['created_by'], 'account_mappings_created_by_index');
            $table->index(['updated_by'], 'account_mappings_updated_by_index');

            $table->foreign('company_id', 'account_mappings_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'account_mappings_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('account_group_id', 'account_mappings_account_group_id_foreign')->references('id')->on('account_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'account_mappings_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'account_mappings_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `account_mappings`
                    ADD CONSTRAINT `account_mappings_rule1_check` CHECK ((`ledger_id` IS NULL) <> (`account_group_id` IS NULL))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
