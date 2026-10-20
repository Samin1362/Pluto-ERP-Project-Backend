<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `tax_rules` (Accounting): migration 39 of 83, dependency level 4.
 * User-defined tax rule (K6). LedgerFlow never assumes rates.
 * Depends on: companies, ledgers, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('User-defined tax rule (K6). LedgerFlow never assumes rates.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('name', 80);
            $table->decimal('rate', 7, 4)->comment('Entered by the user, e.g. 7.5000');
            $table->string('applies_to', 30)->comment('One of: sales, purchases, both');
            $table->unsignedBigInteger('ledger_id')->comment('Liability or asset ledger where the tax collects');
            $table->boolean('is_recoverable')->default(true)->comment('Purchases: recoverable input tax posts to the rule\'s ledger; otherwise it is added to the line cost (R-14)');
            $table->boolean('is_active')->default(true)->comment('Rules used on any line are deactivated, never deleted');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'name'], 'tax_rules_company_id_name_unique');
            $table->index(['ledger_id'], 'tax_rules_ledger_id_index');
            $table->index(['created_by'], 'tax_rules_created_by_index');
            $table->index(['updated_by'], 'tax_rules_updated_by_index');

            $table->foreign('company_id', 'tax_rules_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'tax_rules_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'tax_rules_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'tax_rules_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `tax_rules`
                    ADD CONSTRAINT `tax_rules_applies_to_check` CHECK (`applies_to` IN ('sales', 'purchases', 'both')),
                    ADD CONSTRAINT `tax_rules_rule1_check` CHECK (`rate` >= 0 AND `rate` <= 100)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
