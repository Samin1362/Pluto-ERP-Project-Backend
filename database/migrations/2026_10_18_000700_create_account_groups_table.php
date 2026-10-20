<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `account_groups` (Accounting): migration 7 of 83, dependency level 2.
 * Hierarchical chart-of-accounts groups (Primary > Group > Sub-group). Nature drives reports.
 * Depends on: companies, users. Self-reference: parent_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Hierarchical chart-of-accounts groups (Primary > Group > Sub-group). Nature drives reports.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('NULL for the five primary groups');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('nature', 30)->comment('One of: asset, liability, equity, income, expense');
            $table->string('normal_balance', 30)->comment('One of: debit, credit');
            $table->string('report_section', 30)->comment('One of: balance_sheet, profit_and_loss');
            $table->string('cash_flow_category', 30)->nullable()->comment('One of: operating, investing, financing, cash. Used by the indirect cash flow');
            $table->boolean('is_system')->default(false)->comment('Seeded groups cannot be deleted');
            $table->boolean('used_in_cash_vouchers')->default(false);
            $table->integer('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'code'], 'account_groups_company_id_code_unique');
            $table->index(['parent_id'], 'account_groups_parent_id_index');
            $table->index(['created_by'], 'account_groups_created_by_index');
            $table->index(['updated_by'], 'account_groups_updated_by_index');

            $table->foreign('company_id', 'account_groups_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('parent_id', 'account_groups_parent_id_foreign')->references('id')->on('account_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'account_groups_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'account_groups_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `account_groups`
                    ADD CONSTRAINT `account_groups_nature_check` CHECK (`nature` IN ('asset', 'liability', 'equity', 'income', 'expense')),
                    ADD CONSTRAINT `account_groups_normal_balance_check` CHECK (`normal_balance` IN ('debit', 'credit')),
                    ADD CONSTRAINT `account_groups_report_section_check` CHECK (`report_section` IN ('balance_sheet', 'profit_and_loss')),
                    ADD CONSTRAINT `account_groups_cash_flow_category_check` CHECK (`cash_flow_category` IN ('operating', 'investing', 'financing', 'cash'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_groups');
    }
};
